<?php

namespace App\Controller\Studio;

use App\Api\ExerciseUrls;
use App\Api\StudioCreateInput;
use App\Api\StudioPracticeInput;
use App\Api\StudioSaveInput;
use App\Content\Author\ExerciseAssistant;
use App\Content\Author\ExerciseDrafter;
use App\Content\Author\ExerciseStudio;
use App\Content\ContentException;
use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Content\Pack;
use App\Content\Track;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * L'éditeur d'exercices : ouvrir, enregistrer, créer, supprimer, vérifier et faire corriger un exercice de parcours ou de Pratique.
 *
 * L'atelier des auteurs est réservé à ROLE_AUTEUR (voir security.yaml) : on écrit sur le disque et on exécute le PHP du pack.
 */
#[Route('/atelier')]
final class StudioExerciseController extends AbstractController
{
    public function __construct(
        private readonly ContentRepository $content,
        private readonly ExerciseStudio $studio,
        private readonly ExerciseDrafter $drafter,
        private readonly ExerciseAssistant $assistant,
    ) {
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
                    // Un exercice de Pratique a une page publique : c'est le seul qui se partage.
                    'post' => $owner instanceof Pack ? $this->generateUrl('app_studio_post_pratique', ['exerciseId' => $exercise->id]) : null,
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
            $id = $this->assistant->creer($track, $input->chapitre, $input->id, $input->titre, $input->base, $input->sujet);
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

        try {
            $correction = $this->assistant->proposerCorrection($owner, $exercise);
        } catch (ContentException $e) {
            return $this->json(['erreur' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Proposé, pas enregistré : l'auteur relit avant d'écrire quoi que ce soit.
        return $this->json($correction);
    }

    /** @return array{Track|Pack, Exercise} */
    private function trouver(?string $trackId, string $exerciseId): array
    {
        return $this->studio->trouver($trackId, $exerciseId) ?? throw $this->createNotFoundException();
    }
}
