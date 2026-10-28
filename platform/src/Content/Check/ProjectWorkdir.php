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
        $this->filesystem->mkdir($workdir);
        foreach ($files as $path => $content) {
            $this->filesystem->dumpFile($this->inside($workdir, $path), $content);
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
            $this->filesystem->remove($this->inside($workdir, $dir));
            // Le dossier lui-même doit rester : Laravel refuse de démarrer sans son dossier de vues compilées.
            $this->filesystem->mkdir($workdir.'/'.$dir);
        }
    }

    public function remove(string $workdir): void
    {
        $this->filesystem->remove($workdir);
    }

    /**
     * Le chemin dans le projet, à condition qu'il y reste : ni segment « .. », ni dossier remplacé par un lien
     * symbolique vers l'extérieur. Les tests ont pu modifier le projet entre deux lancements (mutants) : sans cela, on
     * écrirait ou on viderait, par un lien qu'ils ont posé, un dossier de celui qui note.
     */
    private function inside(string $workdir, string $path): string
    {
        if (\in_array('..', explode('/', str_replace('\\', '/', $path)), true)) {
            throw new \RuntimeException(sprintf('Chemin hors du projet : « %s ».', $path));
        }
        // Le cache de realpath() de PHP se souviendrait d'un dossier que les tests ont remplacé depuis par un lien.
        clearstatcache(true);
        $root = realpath($workdir);
        $directory = \dirname($workdir.'/'.$path);
        while (!file_exists($directory) && !is_link($directory) && \strlen($directory) > \strlen($workdir)) {
            $directory = \dirname($directory);
        }
        $real = realpath($directory);
        if (false === $root || false === $real || ($real !== $root && !str_starts_with($real.'/', $root.'/'))) {
            throw new \RuntimeException(sprintf('Chemin hors du projet : « %s ».', $path));
        }

        return $workdir.'/'.$path;
    }
}
