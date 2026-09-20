<?php

namespace App\Content;

use App\Content\Framework\FrameworkProfile;

/**
 * Environnement d'exécution (projet de base) décrit par environments/<id>/environment.yaml.
 *
 * Un environnement peut en **prolonger** un autre (`extends:`) : il ne contient alors que ce qu'il
 * ajoute ou remplace, et le projet joué est la superposition de la chaîne, du plus général au plus
 * particulier. C'est ce qui évite de recopier un squelette Symfony entier pour trois fichiers de
 * différence — et ce qui permettra à un pack d'apporter son décor sans embarquer tout un framework.
 */
final readonly class Environment
{
    /**
     * @param list<string>     $directories les dossiers de la chaîne, de la base à cet environnement :
     *                                      un fichier du dernier l'emporte sur le même fichier du premier
     * @param FrameworkProfile $framework   ce que le moteur sait de ce framework (voir FrameworkRegistry)
     * @param list<string>     $cacheDirs   dossiers de cache à vider entre deux runs de tests
     */
    public function __construct(
        public string $id,
        public string $title,
        /** Vide pour un environnement sans PHP (nuxt). */
        public string $phpVersion,
        public array $directories,
        public FrameworkProfile $framework,
        public array $cacheDirs,
    ) {
    }

    /** Le dossier de cet environnement — le dernier de la chaîne, celui qui porte son environment.yaml. */
    public function directory(): string
    {
        return $this->directories[array_key_last($this->directories)];
    }

    /** Prolonge-t-il un autre environnement ? */
    public function isComposed(): bool
    {
        return \count($this->directories) > 1;
    }

    /**
     * Le chemin d'un fichier du projet, cherché du plus particulier au plus général. Null s'il n'existe
     * dans aucun dossier de la chaîne.
     */
    public function file(string $relative): ?string
    {
        foreach (array_reverse($this->directories) as $directory) {
            if (is_file($directory.'/'.$relative)) {
                return $directory.'/'.$relative;
            }
        }

        return null;
    }

    /**
     * Les fichiers d'un dossier du projet, superposés : à nom égal, celui du plus particulier l'emporte.
     *
     * @return array<string, string> nom du fichier => chemin absolu
     */
    public function filesIn(string $relative): array
    {
        $files = [];
        foreach ($this->directories as $directory) {
            foreach (glob($directory.'/'.$relative.'/*') ?: [] as $path) {
                if (is_file($path)) {
                    $files[basename($path)] = $path;
                }
            }
        }

        return $files;
    }

    /**
     * Le dossier où Composer installe, c'est-à-dire le dernier de la chaîne qui déclare un
     * `composer.json` : un environnement qui n'ajoute aucune dépendance hérite du `vendor/` de sa base.
     *
     * Contrairement au reste, `vendor/` ne se superpose pas : il vient de ce dossier et de lui seul,
     * sans quoi les paquets d'une base qu'un enfant a retirés traîneraient dans le projet joué.
     */
    public function composerDirectory(): ?string
    {
        $composer = $this->file('composer.json');

        return null === $composer ? null : \dirname($composer);
    }

    /** Archive servie au navigateur, produite par environments/bin/build-env.sh. */
    public function archivePath(): string
    {
        return 'envs/'.$this->id.'.zip';
    }

    public function completionIndexPath(): string
    {
        return 'envs/'.$this->id.'.completion.json';
    }
}
