<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Runtime;

use Forelse\DockerSim\Fs\DiskFs;
use Forelse\DockerSim\Fs\Path;
use Forelse\DockerSim\State\Blob;
use Forelse\DockerSim\State\Container;
use Forelse\DockerSim\State\Image;
use Forelse\DockerSim\State\Store;

/**
 * Donne un vrai système de fichiers à un conteneur : la racine (couches de l'image recopiées sur
 * disque, c'est aussi la couche inscriptible du conteneur) et les montages, posés en liens
 * symboliques vers le dossier de l'hôte ou du volume. PHP peut alors exécuter le code servi.
 */
final class Materializer
{
    public function __construct(private readonly Store $store)
    {
    }

    public function rootfs(Container $container): string
    {
        return $this->store->rootfs($container->id);
    }

    public function metaFile(Container $container): string
    {
        return \dirname($this->rootfs($container)).'/meta.json';
    }

    /** Crée la racine du conteneur depuis son image (une seule fois : c'est sa couche inscriptible). */
    public function ensure(Container $container, Image $image): void
    {
        $root = $this->rootfs($container);
        if (is_dir($root)) {
            $this->mount($container);

            return;
        }
        @mkdir($root, 0777, true);
        foreach ($image->directories() as $dir) {
            @mkdir($root.$dir, 0777, true);
        }
        $metadata = $image->metadata();
        foreach ($image->filesystem() as $path => $blob) {
            // Le « reste » simulé des images de base n'a pas besoin d'exister sur disque.
            if (Blob::isVirtual($blob) && str_starts_with($path, '/usr/share/sim-')) {
                continue;
            }
            Blob::write($blob, $root.$path);
            // Un fichier virtuel est vide sur disque : sa taille annoncée (ls -l, du) est gardée à part.
            if (Blob::isVirtual($blob)) {
                $metadata[$path] = [$metadata[$path][0] ?? 0644, $metadata[$path][1] ?? 'root', Blob::size($blob)];
            }
        }
        file_put_contents($this->metaFile($container), json_encode($metadata, \JSON_UNESCAPED_SLASHES));
        $this->mount($container);
    }

    /** Pose les montages : un volume nommé vide reçoit d'abord le contenu de l'image à cet endroit. */
    public function mount(Container $container): void
    {
        $root = $this->rootfs($container);
        $mounts = $container->mounts;
        usort($mounts, static fn ($a, $b) => \strlen($a['target']) <=> \strlen($b['target']));
        $placed = [];
        foreach ($mounts as $mount) {
            $target = Path::normalize($mount['target']);
            foreach ($placed as $parent) {
                if ($parent !== $target && Path::isUnder($target, $parent)) {
                    // Montage imbriqué dans un autre montage : non pris en charge (il écrirait dans l'hôte).
                    continue 2;
                }
            }
            $source = $this->source($mount);
            $link = $root.$target;
            if (is_link($link) && readlink($link) === $source) {
                $placed[] = $target;
                continue;
            }
            if ($mount['type'] === 'volume') {
                @mkdir($source, 0777, true);
                if ($this->isEmptyDir($source) && (is_dir($link) && !is_link($link))) {
                    Store::copyTree($link, $source);
                    // Un volume neuf hérite aussi des droits que l'image avait à cet endroit.
                    $this->seedVolumeMeta($container, $target, $source);
                }
            } elseif (!file_exists($source)) {
                // docker run -v ./dossier:/cible crée le dossier manquant sur l'hôte.
                @mkdir($source, 0777, true);
            }
            @mkdir(\dirname($link), 0777, true);
            Store::removeTree($link);
            if (!@symlink($source, $link)) {
                Store::copyTree($source, $link);
            }
            $placed[] = $target;
        }
    }

    /** @param array{type: string, source: string, target: string, readOnly: bool} $mount */
    public function source(array $mount): string
    {
        return $mount['type'] === 'volume' ? $this->store->volumePath($mount['source']) : $mount['source'];
    }

    /** Modes et propriétaires d'un volume : ils lui appartiennent, et suivent d'un conteneur à l'autre. */
    public function volumeMetaFile(string $name): string
    {
        return \dirname($this->store->volumePath($name)).'/meta.json';
    }

    public function fs(Container $container): DiskFs
    {
        $mounts = [];
        $readOnly = [];
        $volumeMeta = [];
        foreach ($container->mounts as $mount) {
            $target = Path::normalize($mount['target']);
            $mounts[$target] = $this->source($mount);
            if ($mount['readOnly']) {
                $readOnly[] = $mount['target'];
            }
            if ($mount['type'] === 'volume' && $mount['source'] !== '') {
                $volumeMeta[$target] = $this->volumeMetaFile($mount['source']);
            }
        }

        return new DiskFs($this->rootfs($container), $mounts, $this->metaFile($container), $readOnly, $volumeMeta);
    }

    /**
     * Recopie dans le volume les droits que l'image portait sur le dossier monté et son contenu,
     * en chemins relatifs au point de montage.
     */
    private function seedVolumeMeta(Container $container, string $target, string $source): void
    {
        $name = basename(\dirname($source));
        $file = $this->volumeMetaFile($name);
        if (is_file($file)) {
            return;
        }
        $imageMeta = is_file($this->metaFile($container))
            ? (json_decode((string) file_get_contents($this->metaFile($container)), true) ?: [])
            : [];
        $seed = [];
        foreach ($imageMeta as $path => $entry) {
            if (!Path::isUnder((string) $path, $target)) {
                continue;
            }
            $relative = substr((string) $path, \strlen(rtrim($target, '/')));
            $seed[$relative === '' ? '/' : $relative] = $entry;
        }
        @mkdir(\dirname($file), 0777, true);
        file_put_contents($file, json_encode($seed, \JSON_UNESCAPED_SLASHES));
    }

    /** Chemin réel d'un chemin du conteneur. */
    public function real(Container $container, string $path): string
    {
        return $this->fs($container)->real(Path::normalize($path));
    }

    private function isEmptyDir(string $dir): bool
    {
        return !is_dir($dir) || \count(scandir($dir) ?: []) <= 2;
    }
}
