<?php

namespace App\Api;

use App\Ai\Mentor;
use App\Content\Exercise;
use App\Entity\User;
use App\Service\ProgressService;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Qui peut faire appel au mentor, et combien de fois : chaque appel coûte. Le mentor doit être activé, l'exercice
 * ouvert à l'apprenant, son adresse confirmée ; les appels sont limités par compte, par adresse IP et pour l'instance.
 */
final readonly class MentorGate
{
    public function __construct(
        private Mentor $mentor,
        private ExerciseLocator $exercises,
        private ExerciseAccessGuard $guard,
        private ProgressService $progress,
        private RateLimiterFactoryInterface $mentorLimiter,
        #[Autowire(service: 'limiter.mentor_ip')]
        private RateLimiterFactoryInterface $ipLimiter,
        #[Autowire(service: 'limiter.mentor_budget')]
        private RateLimiterFactoryInterface $budget,
        private RequestStack $requests,
        private ClockInterface $clock,
    ) {
    }

    /**
     * L'exercice sur lequel l'apprenant appelle le mentor ; une HttpException qui dit pourquoi, s'il ne le peut pas.
     *
     * @param bool $requireCompleted la revue de code : seulement après la réussite
     */
    public function admit(User $user, ?string $trackId, string $exerciseId, bool $requireCompleted = false): Exercise
    {
        if (!$this->mentor->disponible()) {
            throw new HttpException(Response::HTTP_SERVICE_UNAVAILABLE, 'Le mentor n\'est pas activé sur cette plateforme.');
        }
        $exercise = $this->exercises->find($trackId, $exerciseId) ?? throw new NotFoundHttpException();
        $this->guard->check($user, $exercise);
        // La revue compare au code de référence, que le modèle reçoit : avant la réussite, du code qui lui
        // demanderait de la recopier livrerait la solution sans passer par « Voir la solution » (et sa perte d'XP).
        if ($requireCompleted && !$this->progress->find($user, $exercise)?->isCompleted()) {
            throw new HttpException(Response::HTTP_CONFLICT, 'La revue de code vient après la réussite : faites d\'abord passer les tests.');
        }

        // Une inscription ne coûte rien : sans adresse confirmée, des comptes jetables multiplieraient la facture.
        if (!$user->isEmailVerified()) {
            throw new HttpException(Response::HTTP_PRECONDITION_REQUIRED, 'Confirmez votre adresse email pour faire appel au mentor : le lien est dans l\'email reçu à l\'inscription, ou à renvoyer depuis votre compte.');
        }
        $this->consumeQuota($user);

        return $exercise;
    }

    private function consumeQuota(User $user): void
    {
        $ip = $this->requests->getMainRequest()?->getClientIp() ?? 'inconnue';
        foreach ([$this->mentorLimiter->create((string) $user->getId()), $this->ipLimiter->create($ip)] as $limiter) {
            $limit = $limiter->consume();
            if (!$limit->isAccepted()) {
                throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp(), 'Le mentor a beaucoup travaillé : réessayez un peu plus tard.');
            }
        }
        if (!$this->budget->create('instance')->consume()->isAccepted()) {
            throw new HttpException(Response::HTTP_SERVICE_UNAVAILABLE, 'Le mentor a atteint sa limite du jour sur cette plateforme : il revient demain.');
        }
    }
}
