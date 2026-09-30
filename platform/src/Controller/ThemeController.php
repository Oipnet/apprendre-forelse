<?php

namespace App\Controller;

use App\Theme\Theme;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sert les images du thème de l'instance (logo, favicon, image de partage), déposées dans
 * BRANDING_DIR à côté de theme.yaml. Trois rôles seulement, jamais un chemin : le nom du fichier
 * vient du manifeste, pas de l'URL, donc rien à traverser.
 *
 * L'URL nomme le thème et la date du fichier (voir Theme) : une image qui change, ou un autre thème,
 * change d'URL, et le cache immuable ne sert jamais une image périmée.
 *
 * Les images du moteur, elles, restent des fichiers statiques de public/img servis par le serveur web.
 */
final class ThemeController extends AbstractController
{
    public function __construct(private readonly Theme $theme)
    {
    }

    #[Route('/theme/{theme}/{version}/{role}', name: 'app_theme_image', methods: ['GET'], requirements: ['theme' => '[a-z0-9-]+', 'version' => '\d+', 'role' => 'logo|icon|share'])]
    public function image(string $theme, string $role): BinaryFileResponse
    {
        $path = $theme === $this->theme->id() ? $this->theme->imagePath($role) : null;
        if (null === $path) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->setPublic();
        $response->setMaxAge(86400);
        $response->setImmutable();

        return $response;
    }

    /**
     * L'adresse d'avant le renommage (« /marque/<rôle>?v=… »), encore citée par des pages en cache, des aperçus de
     * partage et des emails déjà envoyés : elle mène à l'image du thème actuel.
     *
     * @deprecated depuis 2.6, retirée en 4.0
     */
    #[Route('/marque/{role}', name: 'app_theme_image_legacy', methods: ['GET'], requirements: ['role' => 'logo|icon|share'])]
    public function legacyImage(string $role): RedirectResponse
    {
        $url = match ($role) {
            'logo' => $this->theme->logoUrl(),
            'icon' => $this->theme->iconUrl(),
            'share' => $this->theme->shareUrl(),
            // Inaccessible (la route n'accepte que les trois rôles), mais un rôle inconnu serait une image introuvable.
            default => null,
        };
        if (null === $url || null === $this->theme->imagePath($role)) {
            throw $this->createNotFoundException();
        }

        return $this->redirect($url, 301);
    }
}
