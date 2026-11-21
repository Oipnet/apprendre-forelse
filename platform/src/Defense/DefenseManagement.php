<?php

namespace App\Defense;

use App\Content\ContentRepository;
use App\Content\Track;
use App\Content\TrackVisibility;
use App\Entity\Cohort;
use App\Entity\Defense;
use App\Entity\DefenseLevel;
use App\Entity\DefenseMode;
use App\Entity\DefenseOutcome;
use App\Entity\User;
use App\Repository\DefenseRepository;
use App\Repository\ExerciseProgressRepository;
use App\Service\TrackProgress;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Les soutenances de fin de parcours : le chef de cohorte ouvre des créneaux, l'apprenant qui a terminé le parcours en
 * réserve un, l'évaluateur (chef ou administrateur) remplit la grille. Tout chemin d'écriture passe par ici.
 */
final readonly class DefenseManagement
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DefenseRepository $defenses,
        private ExerciseProgressRepository $progress,
        private ContentRepository $content,
        private DefenseMailer $mailer,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<Track> les parcours sur lesquels la cohorte peut ouvrir des soutenances : ceux qu'elle propose */
    public function tracksOf(Cohort $cohort): array
    {
        return array_values(array_filter($this->content->tracks(), static fn (Track $track) => TrackVisibility::isOfferedBy([$cohort], $track)));
    }

    public function openSlot(Cohort $cohort, string $trackId, \DateTimeImmutable $startsAt, int $durationMinutes, DefenseMode $mode, string $place): Defense
    {
        $track = $this->content->findTrack($trackId);
        if (null === $track || !TrackVisibility::isOfferedBy([$cohort], $track)) {
            throw new DefenseRuleViolation('Ce parcours n\'est pas proposé à la cohorte.');
        }
        $now = $this->clock->now();
        if ($startsAt <= $now) {
            throw new DefenseRuleViolation('Le créneau doit commencer dans le futur.');
        }
        if ($durationMinutes < Defense::MIN_DURATION || $durationMinutes > Defense::MAX_DURATION) {
            throw new DefenseRuleViolation(sprintf('Une soutenance dure entre %d et %d minutes.', Defense::MIN_DURATION, Defense::MAX_DURATION));
        }
        $place = trim($place);
        if ('' === $place) {
            throw new DefenseRuleViolation(sprintf('Indiquez %s.', DefenseMode::Online === $mode ? 'le lien de la visio' : 'l\'adresse et la salle'));
        }
        if (DefenseMode::Online === $mode && 1 !== preg_match('#^https://\S+$#', $place)) {
            throw new DefenseRuleViolation('Le lien de la visio doit commencer par https://.');
        }

        $defense = new Defense($cohort, $track->id, $startsAt, $durationMinutes, $mode, $place, $now);
        $this->entityManager->persist($defense);
        $this->entityManager->flush();

        return $defense;
    }

    /** Un créneau réservé ne se supprime pas : l'apprenant a reçu sa convocation. */
    public function removeSlot(Defense $defense): void
    {
        if ($defense->isBooked()) {
            throw new DefenseRuleViolation('Ce créneau est réservé : il ne se supprime pas.');
        }
        $this->entityManager->remove($defense);
        $this->entityManager->flush();
    }

    /**
     * Ce que la page du parcours montre à l'apprenant ; null si sa cohorte n'organise pas de soutenance sur ce
     * parcours (et qu'il n'en a jamais passé).
     */
    public function offerFor(User $learner, Track $track): ?DefenseOffer
    {
        $cohort = $learner->getCohort();
        $history = $this->defenses->findByLearner($learner, $track->id);
        if ([] === $history && (null === $cohort || !$this->defenses->cohortHasSlots($cohort, $track->id))) {
            return null;
        }

        $upcoming = array_find($history, static fn (Defense $d) => !$d->isEvaluated());
        $last = array_find($history, static fn (Defense $d) => $d->isEvaluated());
        $complete = $this->isTrackComplete($learner, $track);
        $offer = new DefenseOffer($complete, $upcoming, $last, []);
        if (!$offer->canBook() || null === $cohort) {
            return $offer;
        }

        return new DefenseOffer($complete, $upcoming, $last, $this->defenses->findFreeSlots($cohort, $track->id, $this->clock->now()));
    }

    /**
     * Réserve le créneau, puis envoie la convocation. Deux apprenants sur le même créneau se suivent derrière un verrou
     * sur la ligne : le second le trouve pris.
     */
    public function book(User $learner, Defense $defense): void
    {
        $track = $this->content->findTrack($defense->getTrackId()) ?? throw new DefenseRuleViolation('Ce parcours n\'existe plus.', 'defense.erreur.parcours_disparu');
        if ($learner->getCohort()?->getId() !== $defense->getCohort()->getId()) {
            throw new DefenseRuleViolation('Ce créneau est réservé à une autre cohorte.', 'defense.erreur.autre_cohorte');
        }
        if (!$this->isTrackComplete($learner, $track)) {
            throw new DefenseRuleViolation('La soutenance s\'ouvre une fois tous les exercices du parcours réussis.', 'defense.erreur.parcours_incomplet');
        }

        $this->entityManager->wrapInTransaction(function () use ($learner, $defense): void {
            $this->entityManager->refresh($defense, LockMode::PESSIMISTIC_WRITE);
            if ($defense->isBooked()) {
                throw new DefenseRuleViolation('Ce créneau vient d\'être pris : choisissez-en un autre.', 'defense.erreur.deja_pris');
            }
            if ($defense->hasStarted($this->clock->now())) {
                throw new DefenseRuleViolation('Ce créneau est passé : choisissez-en un autre.', 'defense.erreur.passe');
            }
            foreach ($this->defenses->findByLearner($learner, $defense->getTrackId()) as $other) {
                if (!$other->isEvaluated()) {
                    throw new DefenseRuleViolation('Vous avez déjà un créneau réservé : annulez-le d\'abord pour en changer.', 'defense.erreur.deja_reserve');
                }
                if (DefenseOutcome::Passed === $other->getOutcome()) {
                    throw new DefenseRuleViolation('Votre soutenance est déjà validée.', 'defense.erreur.deja_validee');
                }
            }
            $defense->book($learner, $this->clock->now());
            $this->entityManager->flush();
        });

        $this->mailer->sendConvocation($defense, $track);
    }

    /** L'apprenant libère son créneau, tant que la soutenance n'a pas commencé. */
    public function cancel(User $learner, Defense $defense): void
    {
        if ($defense->getLearner()?->getId() !== $learner->getId() || $defense->isEvaluated()) {
            throw new DefenseRuleViolation('Ce créneau n\'est pas le vôtre.', 'defense.erreur.pas_le_votre');
        }
        if ($defense->hasStarted($this->clock->now())) {
            throw new DefenseRuleViolation('La soutenance a commencé : elle ne s\'annule plus. Écrivez à votre école.', 'defense.erreur.commencee');
        }
        $defense->release();
        $this->entityManager->flush();
    }

    /**
     * Enregistre la grille et le résultat, puis les envoie à l'apprenant. Une évaluation se corrige : l'apprenant reçoit
     * alors la nouvelle version.
     *
     * @param array<string, DefenseLevel> $grades par identifiant de chapitre : tous les chapitres du parcours
     */
    public function evaluate(Defense $defense, User $evaluator, array $grades, DefenseOutcome $outcome, ?string $comment): void
    {
        $learner = $defense->getLearner();
        if (null === $learner) {
            throw new DefenseRuleViolation('Personne n\'a réservé ce créneau : il n\'y a rien à évaluer.');
        }
        $track = $this->content->findTrack($defense->getTrackId()) ?? throw new DefenseRuleViolation('Ce parcours n\'existe plus.');
        $chapterIds = array_map(static fn ($chapter) => $chapter->id, $track->chapters);
        $missing = array_diff($chapterIds, array_keys($grades));
        if ([] !== $missing) {
            throw new DefenseRuleViolation('Évaluez chaque chapitre de la grille.');
        }

        $defense->evaluate($evaluator, array_intersect_key($grades, array_flip($chapterIds)), $outcome, $comment, $this->clock->now());
        $this->entityManager->flush();

        $this->mailer->sendResult($defense, $track);
    }

    private function isTrackComplete(User $learner, Track $track): bool
    {
        return TrackProgress::of($track, $this->progress->findByTrack($learner, $track->id))->isComplete();
    }
}
