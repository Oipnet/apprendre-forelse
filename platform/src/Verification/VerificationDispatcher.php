<?php

namespace App\Verification;

use App\Certificate\CertificateRevocation;
use App\Content\ContentRepository;
use App\Entity\VerificationStatus;
use App\Leaderboard\TrackBonuses;
use App\Repository\ExerciseProgressRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Côté worker (qui a la base) : envoie au verificateur les réussites en attente, et enregistre ses verdicts. Lancé par
 * le planificateur (VerificationSchedule). Une réussite infirmée sort du classement et, si elle comptait dans un
 * certificat, le fait révoquer (l'apprenant est prévenu et peut contester).
 */
final readonly class VerificationDispatcher
{
    /** Réussites envoyées par passage : la file du verificateur reste courte, le reste part au passage suivant. */
    public const int BATCH = 200;
    /** Une demande restée sans verdict (verificateur arrêté, demande perdue) repart au bout de ce délai. */
    public const string RETRY_AFTER = '-1 day';

    public function __construct(
        private VerificationQueue $queue,
        private ExerciseProgressRepository $progress,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private CertificateRevocation $certificates,
        private ContentRepository $content,
        private TrackBonuses $trackBonuses,
    ) {
    }

    /** @return int réussites envoyées */
    #[AsMessageHandler]
    public function request(RequestVerifications $message): int
    {
        if (!$this->queue->isEnabled()) {
            return 0;
        }
        $this->queue->prepare();
        $now = $this->clock->now();
        $sent = 0;
        foreach ($this->progress->findVerificationsToRequest($now->modify(self::RETRY_AFTER), self::BATCH) as $progress) {
            $this->queue->push(new VerificationRequest((int) $progress->getId(), $progress->getTrackId(), $progress->getExerciseId(), $progress->getSubmittedFiles() ?? []));
            $progress->markVerificationRequested($now);
            ++$sent;
        }
        $this->entityManager->flush();

        return $sent;
    }

    /** @return int verdicts enregistrés */
    #[AsMessageHandler]
    public function collect(CollectVerifications $message): int
    {
        if (!$this->queue->isEnabled()) {
            return 0;
        }
        $this->queue->prepare();
        $now = $this->clock->now();
        $recorded = 0;
        foreach ($this->queue->popResults() as $result) {
            $progress = $this->progress->find($result->progressId);
            // Progression supprimée entre-temps (compte effacé) : rien à faire.
            if (null === $progress || !$progress->isCompleted()) {
                continue;
            }
            $newlyFailed = VerificationStatus::Failed === $result->status && VerificationStatus::Failed !== $progress->getVerification();
            $progress->recordVerification($result->status, $result->detail, $now);
            ++$recorded;
            if ($newlyFailed && null !== $progress->getTrackId()) {
                $title = $this->content->findExercise($progress->getTrackId(), $progress->getExerciseId())->title ?? $progress->getExerciseId();
                $this->certificates->revokeForExercise($progress->getUser(), $progress->getTrackId(), $progress->getExerciseId(), sprintf('La réussite de l\'exercice « %s » n\'a pas été confirmée quand nous avons rejoué votre code sur nos serveurs.', $title));
                $this->trackBonuses->revoke($progress->getUser(), $progress->getTrackId());
            }
        }
        $this->entityManager->flush();

        return $recorded;
    }
}
