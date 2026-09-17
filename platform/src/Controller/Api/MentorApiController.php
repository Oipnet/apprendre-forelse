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
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private readonly ExerciseAccessGuard $guard,
    ) {
    }

    #[Route('/{trackId}/{exerciseId}/review', name: 'api_mentor_review', methods: ['POST'])]
    #[Route('/pratique/{exerciseId}/review', name: 'api_mentor_review_pratique', defaults: ['trackId' => null], methods: ['POST'], priority: 1)]
    public function review(?string $trackId, string $exerciseId, #[MapRequestPayload] ReviewInput $input): JsonResponse
    {
        [$user, $exercise] = $this->resolve($trackId, $exerciseId);
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
    private function resolve(?string $trackId, string $exerciseId): array
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

        $limit = $this->mentorLimiter->create((string) $user->getId())->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - time(), 'Le mentor a beaucoup travaillé : réessayez un peu plus tard.');
        }

        return [$user, $exercise];
    }
}
