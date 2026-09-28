<?php

namespace App\Controller\Studio;

use App\Content\Author\ExerciseDrafter;
use App\Content\Author\ExerciseStudio;
use App\Content\Author\StudioCatalog;
use App\Content\ContentRepository;
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
        private readonly StudioCatalog $catalog,
    ) {
    }

    /** La liste des parcours : une fiche par parcours, avec de quoi voir ce qui reste à écrire. */
    #[Route('', name: 'app_studio', methods: ['GET'])]
    public function index(
        #[MapQueryParameter] ?string $etat = null,
        #[MapQueryParameter] ?string $recherche = null,
    ): Response {
        return $this->render('studio/index.html.twig', $this->catalog->liste($etat, $recherche));
    }

    /** Un parcours : ses chapitres en accordéon, leurs exercices, et de quoi en ajouter. */
    #[Route('/{trackId}', name: 'app_studio_track', methods: ['GET'])]
    public function track(string $trackId): Response
    {
        $track = $this->content->findTrack($trackId) ?? throw $this->createNotFoundException();

        return $this->render('studio/track.html.twig', [...$this->catalog->parcours($track), 'ia' => $this->drafter->disponible()]);
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
}
