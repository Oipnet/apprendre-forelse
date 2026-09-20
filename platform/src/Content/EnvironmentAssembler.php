<?php

namespace App\Content;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Reconstitue le projet d'un environnement dans un dossier de travail : la chaîne superposée, du plus
 * général au plus particulier (voir Environment).
 *
 * Deux détails valent d'être dits, parce qu'ils sont faux dans l'implémentation naïve :
 *
 *  - la copie **écrase** (`override`). Sans cela, `Filesystem::mirror` ne remplace un fichier existant
 *    que s'il est plus récent, et les dates d'un dépôt fraîchement cloné sont toutes voisines : le
 *    fichier de l'enfant l'emporterait une fois sur deux, selon la machine ;
 *  - `vendor/` ne se superpose pas. Il vient du seul dossier qui installe, sinon les paquets d'une base
 *    que l'enfant a retirés de son composer.json traîneraient dans le projet testé.
 */
final readonly class EnvironmentAssembler
{
    /** Ce qui n'a rien à faire dans le projet reconstitué : caches, dépendances, artefacts de build. */
    private const array EXCLUDED = ['var', '.phpunit.cache', 'node_modules', '.nuxt', '.output', 'vendor'];

    public function __construct(private Filesystem $filesystem = new Filesystem())
    {
    }

    public function assemble(Environment $environment, string $workdir): void
    {
        foreach ($environment->directories as $directory) {
            $this->filesystem->mirror(
                $directory,
                $workdir,
                (new Finder())->in($directory)->ignoreDotFiles(false)->exclude(self::EXCLUDED),
                ['override' => true],
            );
        }
        $composer = $environment->composerDirectory();
        if (null !== $composer && is_dir($composer.'/vendor')) {
            $this->filesystem->mirror($composer.'/vendor', $workdir.'/vendor', options: ['override' => true]);
        }
    }
}
