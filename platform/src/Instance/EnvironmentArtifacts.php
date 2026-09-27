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

    private const string ARCHIVE = '.zip';
    private const string COMPLETION = '.completion.json';

    /** L'archive d'un environnement, servie au navigateur (produite par environments/bin/build-env.sh). */
    public static function archiveName(string $id): string
    {
        return $id.self::ARCHIVE;
    }

    /** L'index de complétion d'un environnement (produit par environments/bin/build-env.sh). */
    public static function completionName(string $id): string
    {
        return $id.self::COMPLETION;
    }

    /** Le chemin d'un artefact (archiveName(), completionName()), ou null s'il n'a pas été construit. */
    public function path(string $file): ?string
    {
        // Un nom de fichier, jamais un chemin : ces valeurs viennent d'une URL.
        if (1 !== preg_match('/^[a-z0-9-]{1,64}('.preg_quote(self::ARCHIVE, '/').'|'.preg_quote(self::COMPLETION, '/').')$/', $file)) {
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
