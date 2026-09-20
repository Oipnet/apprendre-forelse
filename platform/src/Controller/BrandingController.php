<?php

namespace App\Controller;

use App\Instance\Branding;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sert les images de la marque de l'instance (logo, favicon, image de partage), déposées dans
 * BRANDING_DIR à côté de marque.yaml. Trois rôles seulement, jamais un chemin : le nom du fichier
 * vient du manifeste, pas de l'URL, donc rien à traverser.
 *
 * Les images du moteur, elles, restent des fichiers statiques de public/img servis par le serveur web.
 */
final class BrandingController extends AbstractController
{
    public function __construct(private readonly Branding $branding)
    {
    }

    #[Route('/marque/{role}', name: 'app_brand_image', methods: ['GET'], requirements: ['role' => 'logo|icon|share'])]
    public function image(string $role): BinaryFileResponse
    {
        $path = $this->branding->imagePath($role);
        if (null === $path) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->setPublic();
        // L'URL porte l'horodatage du fichier (voir Branding) : une image qui change change d'URL.
        $response->setMaxAge(86400);
        $response->setImmutable();

        return $response;
    }
}
