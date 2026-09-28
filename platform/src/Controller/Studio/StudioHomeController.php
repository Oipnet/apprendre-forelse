<?php

namespace App\Controller\Studio;

use App\Content\Author\ExerciseDrafter;
use App\Content\Author\ExerciseStudio;
use App\Content\ContentRepository;
use App\Content\Exercise;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Les pages d'entrée de l'atelier : la liste des parcours, un parcours, et la Pratique.
 *
 * L'atelier des auteurs est réservé à ROLE_AUTEUR (voir security.yaml) : on écrit sur le disque et on exécute le PHP du pack.
 */
#[Route('/atelier')]
final class StudioHomeController extends AbstractController
{
    public function __construct(
        private readonly ContentRepository $content,
        private readonly ExerciseStudio $studio,
        private readonly ExerciseDrafter $drafter,
    ) {
    }

    /** La liste des parcours : une fiche par parcours, avec de quoi voir ce qui reste à écrire. */
    #[Route('', name: 'app_studio', methods: ['GET'])]
    public function index(
        #[MapQueryParameter] ?string $etat = null,
        #[MapQueryParameter] ?string $recherche = null,
    ): Response {
        $etat = \in_array($etat, ['publies', 'preparation'], true) ? $etat : null;
        $recherche = trim($recherche ?? '');

        $parcours = [];
        foreach ($this->content->tracks() as $track) {
            $exercices = $this->content->exercisesOfChapter($track);
            $fiches = \count($track->lessonChapters());
            $parcours[] = [
                'track' => $track,
                'pack' => $this->content->packs()[$track->packId],
                'modifiable' => $this->studio->modifiable($track),
                'chapitres' => \count($track->chapters),
                'exercices' => \count($exercices),
                'xp' => array_sum(array_map(static fn (Exercise $e) => $e->xp, $exercices)),
                'fiches' => $fiches,
                'modifie' => $this->studio->modifieLe($track),
            ];
        }

        $retenus = array_filter($parcours, static fn (array $p) => match ($etat) {
            'publies' => !$p['track']->isRestricted(),
            'preparation' => $p['track']->isRestricted(),
            default => true,
        } && self::correspond($p['track']->title.' '.$p['track']->description, $recherche));

        return $this->render('studio/index.html.twig', [
            'parcours' => array_values($retenus),
            'total' => \count($parcours),
            'enPreparation' => \count(array_filter($parcours, static fn (array $p) => $p['track']->isRestricted())),
            'chapitres' => array_sum(array_column($parcours, 'chapitres')),
            'exercices' => array_sum(array_column($parcours, 'exercices')),
            'pratique' => \count($this->content->practices()),
            'filtres' => ['etat' => $etat, 'recherche' => $recherche],
        ]);
    }

    /** Un parcours : ses chapitres en accordéon, leurs exercices, et de quoi en ajouter. */
    #[Route('/{trackId}', name: 'app_studio_track', methods: ['GET'])]
    public function track(string $trackId): Response
    {
        $track = $this->content->findTrack($trackId) ?? throw $this->createNotFoundException();
        $chapitres = [];
        foreach ($track->chapters as $chapitre) {
            $exercices = $this->content->exercisesOfChapter($track, $chapitre);
            $chapitres[] = [
                'chapitre' => $chapitre,
                'exercices' => $exercices,
                'xp' => array_sum(array_map(static fn (Exercise $e) => $e->xp, $exercices)),
            ];
        }

        return $this->render('studio/track.html.twig', [
            'track' => $track,
            'pack' => $this->content->packs()[$track->packId],
            'modifiable' => $this->studio->modifiable($track),
            'chapitres' => $chapitres,
            'exercices' => array_merge(...array_column($chapitres, 'exercices')),
            'xp' => array_sum(array_column($chapitres, 'xp')),
            'fiches' => \count($track->lessonChapters()),
            'modifie' => $this->studio->modifieLe($track),
            'ia' => $this->drafter->disponible(),
        ]);
    }

    /**
     * La Pratique : un seul bloc, tous packs confondus, puisque ces exercices n'appartiennent à aucun parcours.
     * Déclarée avant `/atelier/{trackId}` : « pratique » n'est donc pas un identifiant de parcours utilisable.
     */
    #[Route('/pratique', name: 'app_studio_practice', methods: ['GET'], priority: 2)]
    public function practice(): Response
    {
        return $this->render('studio/practice.html.twig', [
            'pratique' => $this->content->practices(),
            'packsModifiables' => array_filter($this->content->packs(), $this->studio->modifiable(...)),
            'environnements' => $this->studio->environnementsPratique(),
        ]);
    }

    /** Le titre et la description, comme la liste les montre : c'est ce qu'on cherche. */
    private static function correspond(string $haystack, string $mots): bool
    {
        return '' === $mots || false !== mb_stripos($haystack, $mots);
    }
}
