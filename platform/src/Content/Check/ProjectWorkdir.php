<?php

namespace App\Content\Check;

use Symfony\Component\Filesystem\Filesystem;

/** Le projet reconstitué sur le disque, le temps d'une vérification : on y écrit, on en vide les caches. */
final readonly class ProjectWorkdir
{
    public function __construct(
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function create(): string
    {
        return sys_get_temp_dir().'/content-check-'.bin2hex(random_bytes(6));
    }

    /** @param array<string, string> $files contenu par chemin relatif */
    public function write(string $workdir, array $files): void
    {
        foreach ($files as $path => $content) {
            $this->filesystem->dumpFile($workdir.'/'.$path, $content);
        }
    }

    /**
     * Vide les caches du projet (container Symfony, vues Blade compilées…) : le run suivant doit
     * repartir du code courant, y compris quand un fichier a été réécrit dans la même seconde.
     *
     * @param list<string> $cacheDirs voir Environment::$cacheDirs
     */
    public function clearCaches(string $workdir, array $cacheDirs): void
    {
        foreach ($cacheDirs as $dir) {
            $this->filesystem->remove($workdir.'/'.$dir);
            // Le dossier lui-même doit rester : Laravel refuse de démarrer sans son dossier de vues compilées.
            $this->filesystem->mkdir($workdir.'/'.$dir);
        }
    }

    public function remove(string $workdir): void
    {
        $this->filesystem->remove($workdir);
    }
}
