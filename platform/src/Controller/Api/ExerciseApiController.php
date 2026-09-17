<?php

namespace App\Controller\Api;

use App\Api\ExerciseAccessGuard;
use App\Api\ExerciseLocator;
use App\Api\ExercisePayloadFactory;
use App\Content\TrackVisibility;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ExerciseApiController extends AbstractController
{
    #[Route('/api/exercises/{trackId}/{exerciseId}', name: 'api_exercise', methods: ['GET'], format: 'json')]
    #[Route('/api/exercises/pratique/{exerciseId}', name: 'api_exercise_pratique', defaults: ['trackId' => null], methods: ['GET'], format: 'json', priority: 1)]
    public function show(?string $trackId, string $exerciseId, Request $request, ExerciseLocator $exercises, TrackVisibility $visibility, ExercisePayloadFactory $payloads, ExerciseAccessGuard $guard): JsonResponse
    {
        if (null !== $trackId) {
            $visibility->find($trackId) ?? throw $this->createNotFoundException();
        }
        $exercise = $exercises->find($trackId, $exerciseId) ?? throw $this->createNotFoundException();
        $user = $this->getUser();
        $guard->check($user instanceof User ? $user : null, $exercise);

        return $this->json($payloads->create($exercise, $request));
    }
}
