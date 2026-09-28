<?php

namespace App\Service;

use App\Api\FeedbackInput;
use App\Content\Exercise;
use App\Entity\Feedback;
use App\Entity\FeedbackKind;
use App\Entity\User;
use App\Security\QuotaExceeded;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** Les avis des apprenants sur un exercice (bouton « Un avis ? »). */
final readonly class FeedbackService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        /** Par compte (config/packages/rate_limiter.yaml) : de quoi tout dire, pas de quoi remplir la table. */
        #[Autowire(service: 'limiter.feedback')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    /** @throws QuotaExceeded trop d'avis de ce compte : rien n'est enregistré */
    public function record(User $user, Exercise $exercise, FeedbackInput $input): Feedback
    {
        if (!$this->limiter->create((string) $user->getId())->consume()->isAccepted()) {
            throw new QuotaExceeded('Beaucoup de retours en peu de temps : réessayez dans une heure.');
        }
        $feedback = new Feedback(
            $user,
            $exercise->trackId,
            $exercise->id,
            FeedbackKind::from($input->kind),
            trim($input->message),
            min($input->hintsUsed, \count($exercise->hints)),
            $input->completed,
        );
        $this->entityManager->persist($feedback);
        $this->entityManager->flush();

        return $feedback;
    }
}
