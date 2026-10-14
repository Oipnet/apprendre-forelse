<?php

namespace App\Controller\Studio;

use App\Api\StudioSaveInput;
use App\Content\Author\ExerciseStudio;
use App\Content\Author\ExerciseTranslator;
use App\Content\ContentException;
use App\Content\Exercise;
use App\Content\ExerciseReader;
use App\Content\Pack;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La version anglaise d'un exercice de Pratique (`i18n/en/`) : la faire proposer par le modèle, la relire,
 * l'enregistrer et la vérifier, dans le même éditeur que l'exercice français.
 *
 * Seule la Pratique se traduit : les parcours restent en français. L'atelier est réservé à ROLE_AUTEUR.
 */
#[Route('/atelier/pratique/{exerciseId}/anglais')]
final class StudioTranslationController extends AbstractController
{
    public function __construct(
        private readonly ExerciseStudio $studio,
        private readonly ExerciseTranslator $translator,
    ) {
    }

    #[Route('', name: 'app_studio_translation', methods: ['GET'], priority: 3)]
    public function edit(string $exerciseId): Response
    {
        [$pack, $exercise] = $this->trouver($exerciseId);
        $traduction = $this->studio->traduction($exercise, ExerciseTranslator::LANGUE);

        return $this->render('studio/edit.html.twig', [
            'contexte' => 'Pratique',
            'exercise' => $exercise,
            'traduction' => ['retard' => true === $traduction?->outdated, 'francais' => $this->generateUrl('app_studio_exercise_pratique', ['exerciseId' => $exercise->id])],
            'config' => [
                'fichiers' => $this->studio->lireTraduction($exercise, ExerciseTranslator::LANGUE),
                'modifiable' => $this->studio->modifiable($pack),
                'ia' => $this->translator->disponible(),
                'pratique' => true,
                'traduction' => true,
                'urls' => [
                    'enregistrer' => $this->generateUrl('app_studio_translation_save', ['exerciseId' => $exercise->id]),
                    'verifier' => $this->generateUrl('app_studio_translation_check', ['exerciseId' => $exercise->id]),
                    'traduire' => $this->generateUrl('app_studio_translation_draft', ['exerciseId' => $exercise->id]),
                    'jouer' => $this->generateUrl('app_exercise_pratique', ['_locale' => ExerciseTranslator::LANGUE, 'exerciseId' => $exercise->id]),
                    'atelier' => $this->generateUrl('app_studio'),
                ],
            ],
        ]);
    }

    #[Route('', name: 'app_studio_translation_save', methods: ['PUT'], priority: 3)]
    public function save(string $exerciseId, #[MapRequestPayload] StudioSaveInput $input): JsonResponse
    {
        [$pack, $exercise] = $this->trouver($exerciseId);

        try {
            $erreur = $this->studio->enregistrerTraduction($pack, $exercise, ExerciseTranslator::LANGUE, $input->fichiers);
        } catch (ContentException $e) {
            return $this->json(['erreur' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (null !== $erreur) {
            return $this->json(['erreur' => 'Format invalide : rien n\'a été enregistré.', 'format' => $erreur], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['enregistre' => true, 'format' => null]);
    }

    /** content:check sur l'exercice traduit : ses tests rouges au départ, verts avec sa solution. */
    #[Route('/verifier', name: 'app_studio_translation_check', methods: ['POST'], priority: 3)]
    public function check(string $exerciseId): JsonResponse
    {
        [, $exercise] = $this->trouver($exerciseId);
        $traduction = $this->studio->traduction($exercise, ExerciseTranslator::LANGUE);
        if (null === $traduction) {
            return $this->json(['ok' => false, 'erreurs' => ['Aucune traduction enregistrée : enregistrez-la avant de la vérifier.'], 'avertissements' => [], 'objectifs' => [], 'secondes' => 0]);
        }
        $traduit = $traduction->exercise;
        $debut = microtime(true);

        try {
            $resultat = $this->studio->verifier($traduit);
        } catch (ContentException $e) {
            return $this->json(['ok' => false, 'erreurs' => [$e->getMessage()], 'avertissements' => [], 'objectifs' => [], 'secondes' => 0]);
        }

        return $this->json([
            'ok' => $resultat->isOk(),
            'erreurs' => $resultat->errors,
            // Comme content:check : une traduction en retard se joue, mais ne dit plus la même chose que l'exercice français.
            'avertissements' => [...$resultat->warnings, ...($traduction->outdated ? [sprintf('L\'exercice français a changé depuis la traduction : une fois celle-ci à jour, mettez « source: \'%s\' » dans exercise.yaml.', ExerciseReader::fingerprint($exercise->directory))] : [])],
            'objectifs' => array_map(static fn ($objectif, $validé) => ['label' => $objectif->label, 'dejaValide' => $validé], $traduit->objectives, $resultat->objectivesBefore ?: array_fill(0, \count($traduit->objectives), false)),
            'secondes' => round(microtime(true) - $debut, 1),
        ]);
    }

    /** Proposé, pas enregistré : la traduction atterrit dans l'éditeur, l'auteur relit avant d'écrire quoi que ce soit. */
    #[Route('/traduire', name: 'app_studio_translation_draft', methods: ['POST'], priority: 3)]
    public function draft(string $exerciseId): JsonResponse
    {
        [, $exercise] = $this->trouver($exerciseId);

        try {
            $fichiers = $this->translator->traduire($exercise, $this->studio->lireTraduction($exercise, ExerciseTranslator::LANGUE));
        } catch (ContentException $e) {
            return $this->json(['erreur' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['fichiers' => $fichiers]);
    }

    /** @return array{Pack, Exercise} */
    private function trouver(string $exerciseId): array
    {
        [$pack, $exercise] = $this->studio->trouver(null, $exerciseId) ?? throw $this->createNotFoundException();
        \assert($pack instanceof Pack);

        return [$pack, $exercise];
    }
}
