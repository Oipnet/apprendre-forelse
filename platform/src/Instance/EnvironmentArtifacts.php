<?php

namespace App\Instance;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Où trouver les archives d'environnement et leurs index de complétion.
 *
 * Deux endroits : le `public/envs` du moteur, servi directement par le serveur web, et le dossier des
 * environnements installés par l'instance, qui n'est pas dans l'arborescence publique et passe donc par
 * un contrôleur (voir EnvironmentArtifactController).
 */
final readonly class EnvironmentArtifacts
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public/envs')]
        private string $publicDirectory,
        private InstalledEnvironments $installed,
    ) {
    }

    /** Le chemin d'un artefact (`<id>.zip`, `<id>.completion.json`), ou null s'il n'a pas été construit. */
    public function path(string $file): ?string
    {
        // Un nom de fichier, jamais un chemin : ces valeurs viennent d'une URL.
        if (1 !== preg_match('/^[a-z0-9-]{1,64}\.(zip|completion\.json)$/', $file)) {
            return null;
        }
        foreach ([$this->publicDirectory, $this->installed->artifactsDirectory()] as $directory) {
            if (is_file($directory.'/'.$file)) {
                return $directory.'/'.$file;
            }
        }

        return null;
    }
}
