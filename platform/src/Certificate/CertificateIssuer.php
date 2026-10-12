<?php

namespace App\Certificate;

use App\Content\ContentRepository;
use App\Content\Track;
use App\Entity\Certificate;
use App\Entity\User;
use App\Repository\CertificateRepository;
use App\Repository\ExerciseProgressRepository;
use App\Security\TrackAccessChecker;
use App\Service\TrackProgress;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Délivre le certificat de réussite d'un parcours : tous ses exercices réussis, avec un accès au parcours complet (la
 * même règle que le livret). Un exercice dont la solution a été consultée compte : le certificat le montre.
 *
 * La réussite est constatée dans le navigateur : le certificat atteste un parcours terminé sur la plateforme, pas un
 * examen surveillé. La vérification côté serveur peut le révoquer ensuite (CertificateRevocation).
 */
final readonly class CertificateIssuer
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CertificateRepository $certificates,
        private ExerciseProgressRepository $progress,
        private ContentRepository $content,
        private TrackAccessChecker $access,
        private ClockInterface $clock,
    ) {
    }

    public function isEligible(User $user, Track $track): bool
    {
        return $this->access->hasFullAccess($user, $track)
            && TrackProgress::of($track, $this->progress->findByTrack($user, $track->id))->isComplete();
    }

    /**
     * Le certificat en vigueur, ou un nouveau. Derrière un verrou sur le compte : un double envoi du formulaire ne
     * délivre pas deux certificats.
     *
     * @throws \LogicException le parcours n'est pas terminé
     */
    public function issue(User $user, Track $track, string $holderName): Certificate
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $track, $holderName): Certificate {
            $this->entityManager->refresh($user, LockMode::PESSIMISTIC_WRITE);
            if ($existing = $this->certificates->findValid($user, $track->id)) {
                return $existing;
            }
            if (!$this->isEligible($user, $track)) {
                throw new \LogicException(sprintf('Le parcours « %s » n\'est pas terminé.', $track->id));
            }

            $certificate = new Certificate($this->newCode(), $user, $track->id, $track->title, trim($holderName), $this->snapshot($user, $track), $this->clock->now());
            $this->entityManager->persist($certificate);

            return $certificate;
        });
    }

    /**
     * Chaque exercice du parcours, tel qu'il a été réussi.
     *
     * @return list<array{chapter: string, id: string, title: string, hintsUsed: int, hintsAvailable: int, solutionRevealed: bool, xp: int}>
     */
    private function snapshot(User $user, Track $track): array
    {
        $progress = $this->progress->findByTrack($user, $track->id);
        $exercises = [];
        foreach ($track->chapters as $chapter) {
            foreach ($chapter->exerciseIds as $exerciseId) {
                $exercise = $this->content->findExercise($track->id, $exerciseId);
                $done = $progress[$exerciseId] ?? null;
                $exercises[] = [
                    'chapter' => $chapter->title,
                    'id' => $exerciseId,
                    'title' => $exercise->title ?? $exerciseId,
                    'hintsUsed' => $done?->getHintsUsed() ?? 0,
                    'hintsAvailable' => \count($exercise->hints ?? []),
                    'solutionRevealed' => $done?->isSolutionRevealed() ?? false,
                    'xp' => $done?->getXpEarned() ?? 0,
                ];
            }
        }

        return $exercises;
    }

    private function newCode(): string
    {
        do {
            $code = CertificateCode::generate();
        } while (null !== $this->certificates->findByCode($code));

        return $code;
    }
}
