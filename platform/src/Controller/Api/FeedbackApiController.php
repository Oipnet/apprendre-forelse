<?php

namespace App\Controller\Api;

use App\Api\ExerciseAccessGuard;
use App\Api\ExerciseLocator;
use App\Api\FeedbackInput;
use App\Entity\User;
use App\Service\FeedbackService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/** Retours des apprenants sur un exercice. Contrat : playground/src/app/feedback.ts. */
#[Route('/api/feedback', format: 'json')]
final class FeedbackApiController extends AbstractController
{
    public function __construct(
        /** Retours par compte (config/packages/rate_limiter.yaml) : de quoi tout dire, pas de quoi remplir la table. */
        #[Autowire(service: 'limiter.feedback')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/{trackId}/{exerciseId}', name: 'api_feedback', methods: ['POST'])]
    #[Route('/pratique/{exerciseId}', name: 'api_feedback_pratique', defaults: ['trackId' => null], methods: ['POST'], priority: 1)]
    public function send(?string $trackId, string $exerciseId, #[MapRequestPayload] FeedbackInput $input, ExerciseLocator $exercises, ExerciseAccessGuard $guard, FeedbackService $feedback): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            // 401 (et non une redirection vers la connexion) : c'est une API.
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'Connectez-vous pour envoyer un retour.');
        }
        $exercise = $exercises->find($trackId, $exerciseId) ?? throw $this->createNotFoundException();
        // Comme les autres API d'un exercice : un parcours caché n'existe pas (404), un chapitre fermé répond 403.
        $guard->check($user, $exercise);
        if (!$this->limiter->create((string) $user->getId())->consume()->isAccepted()) {
            throw new HttpException(Response::HTTP_TOO_MANY_REQUESTS, 'Beaucoup de retours en peu de temps : réessayez dans une heure.');
        }
        $feedback->record($user, $exercise, $input);

        return $this->json(['ok' => true], Response::HTTP_CREATED);
    }
}
