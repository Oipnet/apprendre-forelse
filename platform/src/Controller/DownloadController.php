<?php

namespace App\Controller;

use App\Content\ContentRepository;
use App\Content\Track;
use App\Content\TrackVisibility;
use App\Entity\User;
use App\Security\TrackAccessChecker;
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
 * Un fichier qu'un parcours déclare (clé « downloads » de track.yaml) est réservé à ceux qui ont accès à tout
 * ce parcours : 403 sinon, et 404 si le parcours est invisible pour le compte. Un fichier qu'aucun parcours ne
 * déclare reste ouvert à tout compte connecté.
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
    public function download(string $fichier, ContentRepository $content, TrackVisibility $visibility, TrackAccessChecker $access): BinaryFileResponse
    {
        // basename() en défense de profondeur : la contrainte de route interdit déjà
        // les barres obliques, donc aucune traversée de chemin n'est possible.
        $chemin = $this->directory.'/'.basename($fichier);
        if (!is_file($chemin)) {
            throw $this->createNotFoundException();
        }

        $tracks = $content->tracksOffering(basename($fichier));
        if ([] !== $tracks) {
            $user = $this->getUser();
            $visible = array_filter($tracks, static fn (Track $track) => null !== $visibility->find($track->id));
            if ([] === $visible) {
                throw $this->createNotFoundException();
            }
            $open = array_filter($visible, static fn (Track $track) => $access->hasFullAccess($user instanceof User ? $user : null, $track));
            if ([] === $open) {
                throw $this->createAccessDeniedException('Ce fichier accompagne un parcours auquel vous n\'avez pas accès.');
            }
        }

        $reponse = new BinaryFileResponse($chemin);
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($fichier));

        return $reponse;
    }
}
