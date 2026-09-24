<?php

namespace App\Controller\Api;

use App\Ai\Mentor;
use App\Api\ExerciseAccessGuard;
use App\Api\ExerciseLocator;
use App\Api\ExplainInput;
use App\Api\ReviewInput;
use App\Content\ContentException;
use App\Content\Exercise;
use App\Entity\User;
use App\Service\ProgressService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le mentor de l'apprenant connecté (revue de code, erreurs expliquées).
 * Contrat : playground/src/app/mentor.ts. Réservé aux comptes, et limité en volume : chaque appel coûte.
 */
#[Route('/api/mentor', format: 'json')]
final class MentorApiController extends AbstractController
{
    public function __construct(
        private readonly ExerciseLocator $exercises,
        private readonly Mentor $mentor,
        private readonly ProgressService $progress,
        private readonly RateLimiterFactoryInterface $mentorLimiter,
        #[Autowire(service: 'limiter.mentor_ip')]
        private readonly RateLimiterFactoryInterface $ipLimiter,
        #[Autowire(service: 'limiter.mentor_budget')]
        private readonly RateLimiterFactoryInterface $budget,
        private readonly RequestStack $requests,
        private readonly ExerciseAccessGuard $guard,
    ) {
    }

    #[Route('/{trackId}/{exerciseId}/review', name: 'api_mentor_review', methods: ['POST'])]
    #[Route('/pratique/{exerciseId}/review', name: 'api_mentor_review_pratique', defaults: ['trackId' => null], methods: ['POST'], priority: 1)]
    public function review(?string $trackId, string $exerciseId, #[MapRequestPayload] ReviewInput $input): JsonResponse
    {
        [$user, $exercise] = $this->resolve($trackId, $exerciseId, requireCompleted: true);
        $review = $this->appeler(fn () => $this->mentor->revue($exercise, $input->files));
        // Conservée avec la progression : l'apprenant la retrouve en revenant, sans nouvel appel.
        $this->progress->saveReview($user, $exercise, $review);

        return $this->json($review);
    }

    #[Route('/{trackId}/{exerciseId}/explain', name: 'api_mentor_explain', methods: ['POST'])]
    #[Route('/pratique/{exerciseId}/explain', name: 'api_mentor_explain_pratique', defaults: ['trackId' => null], methods: ['POST'], priority: 1)]
    public function explain(?string $trackId, string $exerciseId, #[MapRequestPayload] ExplainInput $input): JsonResponse
    {
        [, $exercise] = $this->resolve($trackId, $exerciseId);

        return $this->json($this->appeler(fn () => $this->mentor->expliquer($exercise, $input->files, $input->error, $input->source)));
    }

    /**
     * @template T of array
     *
     * @param callable(): T $appel
     *
     * @return T
     */
    private function appeler(callable $appel): array
    {
        try {
            return $appel();
        } catch (ContentException $e) {
            // Le modèle n'a pas répondu ou a répondu de travers : ce n'est pas la faute de l'apprenant.
            throw new HttpException(Response::HTTP_BAD_GATEWAY, 'Le mentor n\'a pas pu répondre : '.$e->getMessage(), $e);
        }
    }

    /** @return array{User, Exercise} */
    private function resolve(?string $trackId, string $exerciseId, bool $requireCompleted = false): array
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            // 401 (et non une redirection vers la connexion) : c'est une API.
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'Connectez-vous pour faire appel au mentor.');
        }
        if (!$this->mentor->disponible()) {
            throw new HttpException(Response::HTTP_SERVICE_UNAVAILABLE, 'Le mentor n\'est pas activé sur cette plateforme.');
        }
        $exercise = $this->exercises->find($trackId, $exerciseId) ?? throw $this->createNotFoundException();
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
        $ip = $this->requests->getMainRequest()?->getClientIp() ?? 'inconnue';
        foreach ([$this->mentorLimiter->create((string) $user->getId()), $this->ipLimiter->create($ip)] as $limiter) {
            $limit = $limiter->consume();
            if (!$limit->isAccepted()) {
                throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - time(), 'Le mentor a beaucoup travaillé : réessayez un peu plus tard.');
            }
        }
        if (!$this->budget->create('instance')->consume()->isAccepted()) {
            throw new HttpException(Response::HTTP_SERVICE_UNAVAILABLE, 'Le mentor a atteint sa limite du jour sur cette plateforme : il revient demain.');
        }

        return [$user, $exercise];
    }
}
