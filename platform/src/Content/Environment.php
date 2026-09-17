<?php

namespace App\Content;

/** Environnement d'exécution (projet de base) décrit par environments/<id>/environment.yaml. */
final readonly class Environment
{
    public const array FRAMEWORKS = ['symfony', 'laravel', 'docker', 'nuxt'];

    /**
     * @param string       $framework symfony, laravel, docker ou nuxt : dicte la console (bin/console, artisan, docker) et l'organisation du projet
     * @param list<string> $cacheDirs dossiers de cache à vider entre deux runs de tests, relatifs au projet
     */
    public function __construct(
        public string $id,
        public string $title,
        /** Vide pour un environnement sans PHP (nuxt). */
        public string $phpVersion,
        public string $directory,
        public string $framework = 'symfony',
        public array $cacheDirs = ['var/cache'],
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
