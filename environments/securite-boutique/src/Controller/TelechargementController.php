<?php

namespace App\Controller;

use App\Repository\EtiquetteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TelechargementController extends AbstractController
{
    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%dossier_etiquettes%')]
        private readonly string $dossierEtiquettes,
    ) {
    }

    /**
     * FAILLE (chapitre 6, F6.4 — Boss) : traversée de chemin. Le nom de fichier
     * vient de la requête et est concaténé au dossier sans contrôle.
     *   /telechargement?fichier=../../.env
     * Correction : basename() PUIS vérifier que realpath() reste sous le dossier autorisé.
     */
    #[Route('/telechargement', name: 'app_telechargement', methods: ['GET'])]
    public function fichier(Request $request): Response
    {
        $fichier = (string) $request->query->get('fichier', '');
        $chemin = $this->dossierEtiquettes.'/'.$fichier;

        if (!is_file($chemin)) {
            throw $this->createNotFoundException();
        }

        return new BinaryFileResponse($chemin);
    }

    /**
     * Sert une étiquette par son identifiant (usage légitime).
     */
    #[Route('/etiquette/{id}', name: 'app_etiquette', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function etiquette(int $id, EtiquetteRepository $etiquettes): Response
    {
        $etiquette = $etiquettes->find($id);
        if (null === $etiquette) {
            throw $this->createNotFoundException();
        }

        $chemin = $this->dossierEtiquettes.'/'.$etiquette->getChemin();
        if (!is_file($chemin)) {
            throw $this->createNotFoundException();
        }

        return new BinaryFileResponse($chemin);
    }
}
