<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sert les fichiers à télécharger déposés dans DOWNLOADS_DIR par l'intégration
 * continue du dépôt de contenu (par exemple l'archive de la boutique vulnérable
 * du parcours « Sécuriser une application Symfony »). Réservé aux comptes connectés.
 */
final class DownloadController extends AbstractController
{
    public function __construct(
        #[Autowire(env: 'resolve:DOWNLOADS_DIR')]
        private readonly string $directory,
    ) {
    }

    #[Route('/telechargements/{fichier}', name: 'app_download', methods: ['GET'], requirements: ['fichier' => '[A-Za-z0-9._-]+'])]
    #[IsGranted('ROLE_USER')]
    public function download(string $fichier): BinaryFileResponse
    {
        // basename() en défense de profondeur : la contrainte de route interdit déjà
        // les barres obliques, donc aucune traversée de chemin n'est possible.
        $chemin = $this->directory.'/'.basename($fichier);
        if (!is_file($chemin)) {
            throw $this->createNotFoundException();
        }

        $reponse = new BinaryFileResponse($chemin);
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($fichier));

        return $reponse;
    }
}
