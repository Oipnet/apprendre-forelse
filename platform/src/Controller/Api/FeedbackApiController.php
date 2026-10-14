<?php

namespace App\Controller\Api;

use App\Api\ExerciseAccessGuard;
use App\Api\ExerciseLocator;
use App\Api\FeedbackInput;
use App\Entity\User;
use App\Security\QuotaExceeded;
use App\Service\FeedbackService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Retours des apprenants sur un exercice. Contrat : playground/src/app/feedback.ts. */
#[Route(['fr' => '/api/feedback', 'en' => '/en/api/feedback'], format: 'json')]
final class FeedbackApiController extends AbstractController
{
    #[Route('/{trackId}/{exerciseId}', name: 'api_feedback', methods: ['POST'])]
    #[Route('/pratique/{exerciseId}', name: 'api_feedback_pratique', defaults: ['trackId' => null], methods: ['POST'], priority: 1)]
    public function send(?string $trackId, string $exerciseId, #[MapRequestPayload] FeedbackInput $input, ExerciseLocator $exercises, ExerciseAccessGuard $guard, FeedbackService $feedback, TranslatorInterface $translator): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            // 401 (et non une redirection vers la connexion) : c'est une API.
            throw new HttpException(Response::HTTP_UNAUTHORIZED, $translator->trans('api.connexion.retour', [], 'apprentissage'));
        }
        $exercise = $exercises->find($trackId, $exerciseId) ?? throw $this->createNotFoundException();
        // Comme les autres API d'un exercice : un parcours caché n'existe pas (404), un chapitre fermé répond 403.
        $guard->check($user, $exercise);
        try {
            $feedback->record($user, $exercise, $input);
        } catch (QuotaExceeded $e) {
            throw new HttpException(Response::HTTP_TOO_MANY_REQUESTS, $e->getMessage(), $e);
        }

        return $this->json(['ok' => true], Response::HTTP_CREATED);
    }
}
