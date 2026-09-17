<?php

namespace App\Controller;

use App\Api\ExerciseUrls;
use App\Api\StudioCreateInput;
use App\Api\StudioPracticeInput;
use App\Api\StudioLessonInput;
use App\Api\StudioSaveInput;
use App\Content\Author\ExerciseDrafter;
use App\Content\Author\ExerciseStudio;
use App\Content\Author\LessonDrafter;
use App\Content\Chapter;
use App\Content\ContentException;
use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Content\LessonRenderer;
use App\Content\Pack;
use App\Content\Track;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * L'atelier des auteurs : écrire et vérifier les exercices d'un pack, depuis le navigateur.
 *
 * Réservé à ROLE_AUTEUR (voir security.yaml) : on écrit sur le disque et on exécute le PHP du pack.
 */
#[Route('/atelier')]
final class StudioController extends AbstractController
{
    public function __construct(
        private readonly ContentRepository $content,
        private readonly ExerciseStudio $studio,
        private readonly ExerciseDrafter $drafter,
        private readonly LessonDrafter $lessons,
    ) {
    }

    #[Route('', name: 'app_studio', methods: ['GET'])]
    public function index(): Response
    {
        $parcours = [];
        foreach ($this->content->tracks() as $track) {
            $parcours[] = [
                'track' => $track,
                'pack' => $this->content->packs()[$track->packId],
                'modifiable' => $this->studio->modifiable($track),
                'chapitres' => array_map(fn ($chapitre) => [
                    'chapitre' => $chapitre,
                    'exercices' => array_map(fn (string $id) => $this->content->findExercise($track->id, $id), $chapitre->exerciseIds),
                ], $track->chapters),
            ];
        }

        // La Pratique : un seul bloc, tous packs confondus ; on crée dans un pack modifiable.
        $packs = array_filter($this->content->packs(), $this->studio->modifiable(...));
        $environnements = [];
        foreach ($this->content->tracks() as $track) {
            $environnements[] = $track->environment;
            foreach ($track->chapters as $chapitre) {
                $environnements[] = $chapitre->environment;
            }
        }
        foreach ($this->content->practices() as $practice) {
            $environnements[] = $practice->exercise->environment;
        }
        $environnements = array_values(array_unique(array_filter($environnements)));
        sort($environnements);

        return $this->render('studio/index.html.twig', [
            'parcours' => $parcours,
            'ia' => $this->drafter->disponible(),
            'pratique' => $this->content->practices(),
            'packsModifiables' => $packs,
            'environnements' => $environnements,
        ]);
    }

    #[Route('/{trackId}/{exerciseId}', name: 'app_studio_exercise', methods: ['GET'])]
    #[Route('/pratique/{exerciseId}', name: 'app_studio_exercise_pratique', defaults: ['trackId' => null], methods: ['GET'], priority: 1)]
    public function edit(?string $trackId, string $exerciseId, ExerciseUrls $urls): Response
    {
        [$owner, $exercise] = $this->trouver($trackId, $exerciseId);

        return $this->render('studio/edit.html.twig', [
            // Le fil d'Ariane : le parcours, ou la Pratique.
            'contexte' => $owner instanceof Track ? $owner->title : 'Pratique',
            'exercise' => $exercise,
            'config' => [
                'fichiers' => $this->studio->lire($exercise),
                'modifiable' => $this->studio->modifiable($owner),
                'ia' => $this->drafter->disponible(),
                'pratique' => $owner instanceof Pack,
                'urls' => [
                    'enregistrer' => $urls->generate('app_studio_save', $exercise),
                    'verifier' => $urls->generate('app_studio_check', $exercise),
                    'corriger' => $urls->generate('app_studio_fix', $exercise),
                    'supprimer' => $urls->generate('app_studio_delete', $exercise),
                    'jouer' => $urls->generate('app_exercise', $exercise),
                    'atelier' => $this->generateUrl('app_studio'),
                ],
            ],
        ]);
    }

    #[Route('/{trackId}/{exerciseId}', name: 'app_studio_save', methods: ['PUT'])]
    #[Route('/pratique/{exerciseId}', name: 'app_studio_save_pratique', defaults: ['trackId' => null], methods: ['PUT'], priority: 1)]
    public function save(?string $trackId, string $exerciseId, #[MapRequestPayload] StudioSaveInput $input): JsonResponse
    {
        [$owner, $exercise] = $this->trouver($trackId, $exerciseId);

        try {
            $erreur = $this->studio->enregistrer($owner, $exercise, $input->fichiers);
        } catch (ContentException $e) {
            return $this->json(['erreur' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Le format peut être invalide : c'est enregistré quand même, mais on le dit tout de suite.
        return $this->json(['enregistre' => true, 'format' => $erreur]);
    }

    #[Route('/{trackId}/{exerciseId}', name: 'app_studio_delete', methods: ['DELETE'])]
    #[Route('/pratique/{exerciseId}', name: 'app_studio_delete_pratique', defaults: ['trackId' => null], methods: ['DELETE'], priority: 1)]
    public function delete(?string $trackId, string $exerciseId): JsonResponse
    {
        [$owner, $exercise] = $this->trouver($trackId, $exerciseId);

        try {
            $this->studio->supprimer($owner, $exercise);
        } catch (ContentException $e) {
            return $this->json(['erreur' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['supprime' => true, 'url' => $this->generateUrl('app_studio')]);
    }

    #[Route('/{trackId}/{exerciseId}/verifier', name: 'app_studio_check', methods: ['POST'])]
    #[Route('/pratique/{exerciseId}/verifier', name: 'app_studio_check_pratique', defaults: ['trackId' => null], methods: ['POST'], priority: 1)]
    public function check(?string $trackId, string $exerciseId): JsonResponse
    {
        [, $exercise] = $this->trouver($trackId, $exerciseId);
        $debut = microtime(true);

        try {
            $resultat = $this->studio->verifier($exercise);
        } catch (ContentException $e) {
            return $this->json(['ok' => false, 'erreurs' => [$e->getMessage()], 'avertissements' => [], 'objectifs' => []]);
        }

        return $this->json([
            'ok' => $resultat->isOk(),
            'erreurs' => $resultat->errors,
            'avertissements' => $resultat->warnings,
            // Un objectif déjà validé au départ ne fait pas travailler l'apprenant.
            'objectifs' => array_map(static fn ($objectif, $validé) => ['label' => $objectif->label, 'dejaValide' => $validé], $exercise->objectives, $resultat->objectivesBefore ?: array_fill(0, \count($exercise->objectives), false)),
            'secondes' => round(microtime(true) - $debut, 1),
        ]);
    }

    #[Route('/pratique/nouveau', name: 'app_studio_create_pratique', methods: ['POST'], priority: 2)]
    public function createPractice(#[MapRequestPayload] StudioPracticeInput $input): JsonResponse
    {
        $pack = $this->content->packs()[$input->pack] ?? throw $this->createNotFoundException();

        try {
            $id = $this->studio->creerPratique($pack, $input->id, $input->titre, $input->environnement);
        } catch (ContentException $e) {
            return $this->json(['erreur' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'id' => $id,
            'url' => $this->generateUrl('app_studio_exercise_pratique', ['exerciseId' => $id]),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{trackId}/nouveau', name: 'app_studio_create', methods: ['POST'])]
    public function create(string $trackId, #[MapRequestPayload] StudioCreateInput $input): JsonResponse
    {
        $track = $this->content->findTrack($trackId) ?? throw $this->createNotFoundException();

        try {
            $base = $input->base ? $this->content->findExercise($trackId, $input->base) : null;
            $brouillon = '' === trim($input->sujet) ? null : $this->drafter->brouillon($track, $input->id, $input->titre, $input->sujet, $base);
            $id = $this->studio->creer($track, $input->chapitre, $input->id, $input->titre, $input->base ?: null, $brouillon);
        } catch (ContentException $e) {
            return $this->json(['erreur' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'id' => $id,
            'url' => $this->generateUrl('app_studio_exercise', ['trackId' => $trackId, 'exerciseId' => $id]),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{trackId}/{exerciseId}/corriger', name: 'app_studio_fix', methods: ['POST'])]
    #[Route('/pratique/{exerciseId}/corriger', name: 'app_studio_fix_pratique', defaults: ['trackId' => null], methods: ['POST'], priority: 1)]
    public function fix(?string $trackId, string $exerciseId): JsonResponse
    {
        [$owner, $exercise] = $this->trouver($trackId, $exerciseId);
        $track = $owner instanceof Track ? $owner : null;
        $verdict = $this->studio->verifier($exercise);
        if ($verdict->isOk()) {
            return $this->json(['erreur' => 'L\'exercice est déjà conforme : rien à corriger.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $fichiers = $this->drafter->corriger($track, $exercise, $this->studio->lire($exercise), $verdict->errors);
        } catch (ContentException $e) {
            return $this->json(['erreur' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Proposé, pas enregistré : l'auteur relit avant d'écrire quoi que ce soit.
        return $this->json(['fichiers' => $fichiers, 'erreurs' => $verdict->errors]);
    }

    // --- Fiches de cours -----------------------------------------------------------------

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
                $this->lessons->supprimer($track, $chapter);
            } else {
                $this->lessons->ecrire($track, $chapter, rtrim($input->markdown)."\n", force: true);
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
        $track = $this->content->findTrack($trackId) ?? throw $this->createNotFoundException();
        $chapter = $this->content->findChapter($track, $chapterId) ?? throw $this->createNotFoundException();

        return [$track, $chapter];
    }

    /**
     * L'exercice et ce qui le contient : son parcours, ou son pack pour un exercice de Pratique ($trackId null).
     *
     * @return array{Track|Pack, Exercise}
     */
    private function trouver(?string $trackId, string $exerciseId): array
    {
        if (null === $trackId) {
            $practice = $this->content->findPractice($exerciseId) ?? throw $this->createNotFoundException();

            return [$this->content->packs()[$practice->packId], $practice->exercise];
        }
        $track = $this->content->findTrack($trackId) ?? throw $this->createNotFoundException();
        $exercise = $this->content->findExercise($trackId, $exerciseId) ?? throw $this->createNotFoundException();

        return [$track, $exercise];
    }
}
