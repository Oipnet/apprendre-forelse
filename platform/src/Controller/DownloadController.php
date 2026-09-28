<?php

namespace App\Controller;

use App\Content\ContentRepository;
use App\Entity\User;
use App\Security\DownloadDecision;
use App\Security\DownloadPolicy;
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
 *
 * Un fichier réservé à un parcours (voir DownloadPolicy) répond 403 sans accès, 404 si le parcours est invisible.
 */
final class DownloadController extends AbstractController
{
    public function __construct(
        #[Autowire(env: 'resolve:DOWNLOADS_DIR')]
        private readonly string $directory,
    ) {
    }

    #[Route('/telechargements/{fichier}', name: 'app_download', methods: ['GET'], requirements: ['fichier' => ContentRepository::DOWNLOAD_NAME])]
    #[IsGranted('ROLE_USER')]
    public function download(string $fichier, DownloadPolicy $policy): BinaryFileResponse
    {
        // basename() en défense de profondeur : la contrainte de route interdit déjà
        // les barres obliques, donc aucune traversée de chemin n'est possible.
        $fichier = basename($fichier);
        $chemin = $this->directory.'/'.$fichier;
        if (!is_file($chemin)) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUser();
        match ($policy->decide($user instanceof User ? $user : null, $fichier)) {
            DownloadDecision::Allowed => null,
            DownloadDecision::Hidden => throw $this->createNotFoundException(),
            DownloadDecision::Denied => throw $this->createAccessDeniedException('Ce fichier accompagne un parcours auquel vous n\'avez pas accès.'),
        };

        $reponse = new BinaryFileResponse($chemin);
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $fichier);

        return $reponse;
    }
}
