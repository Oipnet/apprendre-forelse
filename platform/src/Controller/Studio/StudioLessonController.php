<?php

namespace App\Controller\Studio;

use App\Api\StudioLessonInput;
use App\Content\Author\ExerciseDrafter;
use App\Content\Author\ExerciseStudio;
use App\Content\Author\LessonDrafter;
use App\Content\Author\LessonFiles;
use App\Content\Chapter;
use App\Content\ContentException;
use App\Content\LessonRenderer;
use App\Content\Track;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Les fiches de cours d'un chapitre : l'éditeur, l'enregistrement, l'aperçu, le squelette et le brouillon rédigé par le modèle.
 *
 * L'atelier des auteurs est réservé à ROLE_AUTEUR (voir security.yaml) : on écrit sur le disque et on exécute le PHP du pack.
 */
#[Route('/atelier')]
final class StudioLessonController extends AbstractController
{
    public function __construct(
        private readonly ExerciseStudio $studio,
        private readonly ExerciseDrafter $drafter,
        private readonly LessonDrafter $lessons,
        private readonly LessonFiles $lessonFiles,
    ) {
    }

    #[Route('/{trackId}/chapitre/{chapterId}', name: 'app_studio_lesson', methods: ['GET'])]
    public function lesson(string $trackId, string $chapterId): Response
    {
        [$track, $chapter] = $this->trouverChapitre($trackId, $chapterId);
        $routes = ['trackId' => $trackId, 'chapterId' => $chapterId];

        return $this->render('studio/lesson.html.twig', [
            'track' => $track,
            'chapter' => $chapter,
            'config' => [
                'markdown' => (string) $chapter->lesson,
                'modifiable' => $this->studio->modifiable($track),
                'ia' => $this->drafter->disponible(),
                'urls' => [
                    'enregistrer' => $this->generateUrl('app_studio_lesson_save', $routes),
                    'apercu' => $this->generateUrl('app_studio_lesson_preview', $routes),
                    'squelette' => $this->generateUrl('app_studio_lesson_skeleton', $routes),
                    'rediger' => $this->generateUrl('app_studio_lesson_draft', $routes),
                    'lire' => $this->generateUrl('app_chapter', $routes),
                    'atelier' => $this->generateUrl('app_studio'),
                ],
            ],
        ]);
    }

    /** Enregistre la fiche ; un contenu vide la retire du pack. */
    #[Route('/{trackId}/chapitre/{chapterId}', name: 'app_studio_lesson_save', methods: ['PUT'])]
    public function saveLesson(string $trackId, string $chapterId, #[MapRequestPayload] StudioLessonInput $input): JsonResponse
    {
        [$track, $chapter] = $this->trouverChapitre($trackId, $chapterId);

        try {
            if ('' === trim($input->markdown)) {
                $this->lessonFiles->supprimer($track, $chapter);
            } else {
                $this->lessonFiles->ecrire($track, $chapter, rtrim($input->markdown)."\n", force: true);
            }
        } catch (ContentException $e) {
            return $this->json(['erreur' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['enregistre' => true, 'fiche' => '' !== trim($input->markdown)]);
    }

    /** Le rendu exact de la plateforme (commonmark + coloration), pour l'aperçu de l'éditeur. */
    #[Route('/{trackId}/chapitre/{chapterId}/apercu', name: 'app_studio_lesson_preview', methods: ['POST'])]
    public function previewLesson(string $trackId, string $chapterId, #[MapRequestPayload] StudioLessonInput $input, LessonRenderer $renderer): JsonResponse
    {
        $this->trouverChapitre($trackId, $chapterId);

        return $this->json(['html' => $renderer->toHtml($input->markdown)]);
    }

    /** Un squelette assemblé à partir des exercices du chapitre : proposé, pas enregistré. */
    #[Route('/{trackId}/chapitre/{chapterId}/squelette', name: 'app_studio_lesson_skeleton', methods: ['POST'])]
    public function skeletonLesson(string $trackId, string $chapterId): JsonResponse
    {
        [$track, $chapter] = $this->trouverChapitre($trackId, $chapterId);

        return $this->json(['markdown' => $this->lessons->squelette($track, $chapter)]);
    }

    /** Un brouillon rédigé par le modèle : proposé, pas enregistré. */
    #[Route('/{trackId}/chapitre/{chapterId}/rediger', name: 'app_studio_lesson_draft', methods: ['POST'])]
    public function draftLesson(string $trackId, string $chapterId): JsonResponse
    {
        [$track, $chapter] = $this->trouverChapitre($trackId, $chapterId);

        try {
            return $this->json(['markdown' => $this->lessons->brouillon($track, $chapter)]);
        } catch (ContentException $e) {
            return $this->json(['erreur' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /** @return array{Track, Chapter} */
    private function trouverChapitre(string $trackId, string $chapterId): array
    {
        return $this->studio->trouverChapitre($trackId, $chapterId) ?? throw $this->createNotFoundException();
    }
}
