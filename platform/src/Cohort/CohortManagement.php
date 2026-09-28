<?php

namespace App\Cohort;

use App\Content\ContentRepository;
use App\Entity\Cohort;
use App\Entity\User;
use App\Repository\CohortRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les écritures d'une cohorte et de ses apprenants, avec les accès qu'elle ouvre (CohortAccessSync) : dans la même
 * transaction, pour qu'un échec n'enregistre pas l'un sans les autres. Tout chemin d'écriture passe par ici.
 */
final readonly class CohortManagement
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CohortAccessSync $access,
        private CohortRepository $cohorts,
        private ContentRepository $content,
        private CohortQuoteEstimator $estimator,
    ) {
    }

    public function create(Cohort $cohort): void
    {
        $cohort->addRandomCodePart();
        $this->entityManager->wrapInTransaction(function () use ($cohort): void {
            $this->entityManager->persist($cohort);
            $this->entityManager->flush();
            $this->access->sync($cohort);
        });
    }

    /** Après un changement de parcours, de dates ou de mode de financement. */
    public function update(Cohort $cohort): void
    {
        $this->entityManager->wrapInTransaction(function () use ($cohort): void {
            $this->entityManager->persist($cohort);
            $this->entityManager->flush();
            $this->access->sync($cohort);
        });
    }

    /** Les accès ouverts par la cohorte ne lui survivent pas (la progression, si). */
    public function delete(Cohort $cohort): void
    {
        $this->entityManager->wrapInTransaction(function () use ($cohort): void {
            $this->access->revokeAll($cohort);
            $this->entityManager->remove($cohort);
            $this->entityManager->flush();
        });
    }

    /**
     * Les parcours proposés par la cohorte, choisis par son chef. Les parcours en préparation ne changent que si
     * $canUnlockRestricted (un administrateur) : pour les autres, leur état d'origine est conservé.
     *
     * @param list<string> $trackIds
     *
     * @throws CohortRuleViolation cohorte financée par l'établissement sans aucun parcours
     */
    public function chooseTracks(Cohort $cohort, array $trackIds, bool $canUnlockRestricted): void
    {
        $locked = $this->lockedTrackIds($canUnlockRestricted);
        $chosen = array_diff($trackIds, $locked);
        $kept = array_intersect($cohort->getAvailableTrackIds(), $locked);
        // Ordre du catalogue, pour un affichage stable.
        $selection = array_values(array_filter(array_keys($this->content->tracks()), static fn (string $id) => \in_array($id, [...$chosen, ...$kept], true)));
        if (!$selection && $cohort->isFundedByInstitution()) {
            // « Aucune sélection = tous les parcours » ouvrirait, sans devis, chaque parcours publié plus tard.
            throw new CohortRuleViolation('Une cohorte financée par l\'établissement propose au moins un parcours : rien n\'a été enregistré.');
        }
        // Financée par l'établissement : accès ouverts pour les parcours ajoutés, révoqués pour les parcours retirés.
        $this->entityManager->wrapInTransaction(function () use ($cohort, $selection): void {
            $cohort->setAvailableTrackIds($selection);
            $this->access->sync($cohort);
        });
    }

    /** L'effectif prévu, base de l'estimation du devis : le seul champ du financement ouvert au chef de cohorte. */
    public function changeExpectedHeadcount(Cohort $cohort, int $headcount): void
    {
        $cohort->setExpectedHeadcount($headcount);
        $this->entityManager->flush();
    }

    /** Copie l'estimation dans le devis : point de départ, à corriger avant de l'envoyer. */
    public function applyEstimatedQuote(Cohort $cohort): CohortQuote
    {
        $quote = $this->estimator->estimate($cohort);
        $cohort->setQuoteAmount($quote->amount);
        $this->entityManager->flush();

        return $quote;
    }

    /** @return list<string> parcours dont la case ne peut pas changer : ceux en préparation, sauf pour un administrateur */
    public function lockedTrackIds(bool $canUnlockRestricted): array
    {
        if ($canUnlockRestricted) {
            return [];
        }

        return array_values(array_map(static fn ($track) => $track->id, array_filter($this->content->tracks(), static fn ($track) => $track->isRestricted())));
    }

    /**
     * Enregistre le compte modifié (par l'admin) : changer de cohorte ferme les accès de l'ancienne et ouvre ceux de la
     * nouvelle. L'ancienne cohorte est celle lue en base, avant ce changement.
     */
    public function moveLearner(User $user): void
    {
        $previous = $this->entityManager->getUnitOfWork()->getOriginalEntityData($user)['cohort'] ?? null;
        // Le compte et ses accès ensemble : un échec ne laisse pas l'apprenant dans une cohorte sans ses parcours.
        $this->entityManager->wrapInTransaction(function () use ($user, $previous): void {
            $this->entityManager->persist($user);
            $this->entityManager->flush();
            $current = $user->getCohort();
            if ($previous === $current) {
                return;
            }
            if ($previous instanceof Cohort) {
                $this->access->leave($user, $previous);
            }
            if (null !== $current) {
                $this->access->join($user, $current);
            }
        });
    }

    /**
     * Enregistre un nouveau compte, dans la cohorte du code d'invitation s'il en donne un (actif). Le compte et les
     * accès de sa cohorte ensemble, ou rien : un compte sans ses accès ne pourrait plus se réinscrire (email pris), et
     * resterait privé des parcours payés par l'établissement.
     */
    public function enroll(User $user, ?string $code): void
    {
        $code = trim((string) $code);
        if ('' !== $code) {
            $user->setCohort($this->cohorts->findActiveByCode($code));
        }
        $this->entityManager->wrapInTransaction(function () use ($user): void {
            $this->entityManager->persist($user);
            if (null !== $user->getCohort()) {
                // Cohorte financée par l'établissement : ses parcours s'ouvrent dès l'inscription.
                $this->access->join($user, $user->getCohort());
            }
        });
    }
}
