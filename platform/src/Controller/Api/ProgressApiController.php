<?php

namespace App\Controller\Api;

use App\Api\CompletionInput;
use App\Api\DraftInput;
use App\Api\ExerciseAccessGuard;
use App\Api\GuestProgressInput;
use App\Api\ExerciseLocator;
use App\Content\Exercise;
use App\Entity\User;
use App\Service\ProgressService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Progression de l'apprenant connecté. Contrat : playground/src/app/progress.ts (ApiProgressStore). */
#[Route('/api/progress', format: 'json')]
final class ProgressApiController extends AbstractController
{
    private const int MAX_IMPORT = 200;

    public function __construct(
        private readonly ExerciseLocator $exercises,
        private readonly ProgressService $progress,
        private readonly ExerciseAccessGuard $guard,
    ) {
    }

    #[Route('/{trackId}/{exerciseId}', name: 'api_progress_show', methods: ['GET'])]
    #[Route('/pratique/{exerciseId}', name: 'api_progress_show_pratique', defaults: ['trackId' => null], methods: ['GET'], priority: 1)]
    public function show(?string $trackId, string $exerciseId): Response
    {
        [$user, $exercise] = $this->resolve($trackId, $exerciseId);
        $progress = $this->progress->find($user, $exercise);
        if (!$progress) {
            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        return $this->json([
            'files' => (object) $progress->getFiles(),
            'hintsUsed' => $progress->getHintsUsed(),
            'completed' => $progress->isCompleted(),
            'solutionRevealed' => $progress->isSolutionRevealed(),
            'review' => $progress->getReview(),
        ]);
    }

    #[Route('/{trackId}/{exerciseId}', name: 'api_progress_save', methods: ['PUT'])]
    #[Route('/pratique/{exerciseId}', name: 'api_progress_save_pratique', defaults: ['trackId' => null], methods: ['PUT'], priority: 1)]
    public function save(?string $trackId, string $exerciseId, #[MapRequestPayload] DraftInput $input): Response
    {
        [$user, $exercise] = $this->resolve($trackId, $exerciseId);
        $this->progress->saveDraft($user, $exercise, $input->files, $input->hintsUsed);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/{trackId}/{exerciseId}/complete', name: 'api_progress_complete', methods: ['POST'])]
    #[Route('/pratique/{exerciseId}/complete', name: 'api_progress_complete_pratique', defaults: ['trackId' => null], methods: ['POST'], priority: 1)]
    public function complete(?string $trackId, string $exerciseId, #[MapRequestPayload] CompletionInput $input): JsonResponse
    {
        [$user, $exercise] = $this->resolve($trackId, $exerciseId);

        return $this->json($this->progress->complete($user, $exercise, $input->hintsUsed));
    }

    /** La solution de référence, contre l'XP de l'exercice (voir ProgressService::revealSolution). */
    #[Route('/{trackId}/{exerciseId}/solution', name: 'api_progress_solution', methods: ['POST'])]
    #[Route('/pratique/{exerciseId}/solution', name: 'api_progress_solution_pratique', defaults: ['trackId' => null], methods: ['POST'], priority: 1)]
    public function solution(?string $trackId, string $exerciseId): JsonResponse
    {
        [$user, $exercise] = $this->resolve($trackId, $exerciseId);

        return $this->json(['files' => (object) $this->progress->revealSolution($user, $exercise)]);
    }

    /**
     * @param list<GuestProgressInput> $items
     */
    #[Route('/import', name: 'api_progress_import', methods: ['POST'], priority: 10)]
    public function import(#[MapRequestPayload(type: GuestProgressInput::class)] array $items): JsonResponse
    {
        // Largement plus que les exercices qu'un invité peut jouer ; chaque entrée coûte des écritures en base.
        if (\count($items) > self::MAX_IMPORT) {
            throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, sprintf('Au plus %d exercices par import.', self::MAX_IMPORT));
        }

        return $this->json(['imported' => $this->progress->importGuestProgress($this->user(), $items)]);
    }

    /** @return array{User, Exercise} */
    private function resolve(?string $trackId, string $exerciseId): array
    {
        $user = $this->user();
        $exercise = $this->exercises->find($trackId, $exerciseId) ?? throw $this->createNotFoundException();
        // Un chapitre fermé (accès expiré, pas encore acheté) : la progression reste en base, mais n'avance plus.
        $this->guard->check($user, $exercise);

        return [$user, $exercise];
    }

    private function user(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            // 401 (et non une redirection vers la connexion) : c'est une API.
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'Connectez-vous pour sauvegarder votre progression.');
        }

        return $user;
    }
}
