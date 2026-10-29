<?php

namespace App\Api;

use App\Instance\EnvironmentArtifacts;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Les adresses absolues des archives et index d'un environnement, données au playground.
 *
 * Elles gardent la même URL d'une version à l'autre, et le navigateur les garde un jour en cache : leur date de
 * construction (`?v=`) les distingue (voir EnvironmentArtifactController).
 */
final readonly class EnvironmentAssetUrls
{
    public function __construct(
        private Packages $packages,
        private EnvironmentArtifacts $artifacts,
        #[Autowire('%kernel.project_dir%/public')] private string $publicDir = __DIR__.'/../../public',
    ) {
    }

    public function url(string $path, Request $request): string
    {
        // L'archive est soit dans public/envs, soit dans les environnements installés : sa date
        // vient de là où elle est réellement, sinon une archive installée n'aurait pas de version.
        $built = @filemtime($this->artifacts->path(basename($path)) ?? $this->publicDir.'/'.$path);

        return $request->getSchemeAndHttpHost().$this->packages->getUrl($path).(false === $built ? '' : '?v='.$built);
    }

    /** L'adresse d'un fichier construit dans public/ (catalogue des paquets du bac à sable), ou null s'il n'existe pas. */
    public function builtUrl(string $path, Request $request): ?string
    {
        return is_file($this->publicDir.'/'.$path) ? $this->url($path, $request) : null;
    }
}
