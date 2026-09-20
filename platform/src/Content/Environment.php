<?php

namespace App\Content;

use App\Content\Framework\FrameworkProfile;

/** Environnement d'exécution (projet de base) décrit par environments/<id>/environment.yaml. */
final readonly class Environment
{
    /**
     * @param FrameworkProfile $framework ce que le moteur sait de ce framework : console, dossiers,
     *                                    lanceur de tests, conventions (voir FrameworkRegistry)
     * @param list<string>     $cacheDirs dossiers de cache à vider entre deux runs de tests, relatifs au projet
     */
    public function __construct(
        public string $id,
        public string $title,
        /** Vide pour un environnement sans PHP (nuxt). */
        public string $phpVersion,
        public string $directory,
        public FrameworkProfile $framework,
        public array $cacheDirs,
    ) {
    }

    /** Archive servie au navigateur, produite par tools/build-env.sh. */
    public function archivePath(): string
    {
        return 'envs/'.$this->id.'.zip';
    }

    public function completionIndexPath(): string
    {
        return 'envs/'.$this->id.'.completion.json';
    }
}
