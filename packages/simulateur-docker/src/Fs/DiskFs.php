<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Fs;

/**
 * Système de fichiers d'un conteneur, sur disque : sa racine (rootfs) et ses montages
 * (dossiers de l'hôte, volumes). Un chemin du conteneur est traduit vers le vrai chemin, le
 * montage le plus profond l'emportant. Modes et propriétaires sont conservés à part (meta.json) :
 * le système de fichiers réel (MEMFS dans le navigateur) ne les garde pas de façon fiable.
 */
final class DiskFs implements FileSystem
{
    /**
     * Modes et propriétaires, par « territoire » : '' pour la couche du conteneur, et la cible de
     * chaque volume monté pour le volume lui-même — un volume garde ses droits d'un conteneur à l'autre.
     *
     * @var array<string,array<string,array{0:int,1:string}>>
     */
    private array $meta = [];

    /** @var array<string,string> territoire => fichier où il est enregistré */
    private array $metaFiles = [];

    /**
     * @param array<string,string> $mounts     cible dans le conteneur => dossier réel
     * @param list<string>         $readOnly   cibles montées en lecture seule
     * @param array<string,string> $volumeMeta cible d'un volume => fichier de ses modes et propriétaires
     */
    public function __construct(
        private readonly string $root,
        private readonly array $mounts = [],
        private readonly string $metaFile = '',
        private readonly array $readOnly = [],
        array $volumeMeta = [],
    ) {
        $this->metaFiles = ['' => $metaFile];
        foreach ($volumeMeta as $target => $file) {
            $this->metaFiles[Path::normalize($target)] = $file;
        }
        foreach ($this->metaFiles as $territory => $file) {
            $this->meta[$territory] = $file !== '' && is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        }
    }

    /**
     * Le territoire d'un chemin (le volume le plus profond qui le contient, sinon la couche du
     * conteneur) et sa clé dans ce territoire.
     *
     * @return array{0:string,1:string}
     */
    private function territory(string $path): array
    {
        $best = '';
        foreach (array_keys($this->metaFiles) as $target) {
            if ($target !== '' && Path::isUnder($path, $target) && \strlen($target) > \strlen($best)) {
                $best = $target;
            }
        }
        if ($best === '') {
            return ['', $path];
        }
        $relative = substr($path, \strlen(rtrim($best, '/')));

        return [$best, $relative === '' ? '/' : $relative];
    }

    public function real(string $path): string
    {
        $best = null;
        foreach ($this->mounts as $target => $source) {
            if (Path::isUnder($path, $target) && ($best === null || \strlen($target) > \strlen($best))) {
                $best = $target;
            }
        }
        if ($best !== null) {
            return rtrim($this->mounts[$best], '/').substr($path, \strlen(rtrim($best, '/')));
        }

        return rtrim($this->root, '/').$path;
    }

    public function isReadOnly(string $path): bool
    {
        foreach ($this->readOnly as $target) {
            if (Path::isUnder($path, $target)) {
                return true;
            }
        }

        return false;
    }

    public function exists(string $path): bool
    {
        return file_exists($this->real($path)) || isset($this->mounts[$path]);
    }

    public function isDir(string $path): bool
    {
        return is_dir($this->real($path));
    }

    public function isFile(string $path): bool
    {
        return is_file($this->real($path));
    }

    public function read(string $path): ?string
    {
        $real = $this->real($path);

        return is_file($real) ? (string) file_get_contents($real) : null;
    }

    public function write(string $path, string $content, ?int $mode = null, ?string $owner = null): void
    {
        $real = $this->real($path);
        @mkdir(\dirname($real), 0777, true);
        file_put_contents($real, $content);
        $this->touchMeta($path, $mode, $owner);
    }

    public function writeFromHost(string $path, string $hostPath, ?int $mode = null, ?string $owner = null): void
    {
        $real = $this->real($path);
        @mkdir(\dirname($real), 0777, true);
        @copy($hostPath, $real);
        $this->touchMeta($path, $mode, $owner);
    }

    public function mkdir(string $path, ?string $owner = null): void
    {
        @mkdir($this->real($path), 0777, true);
        if ($owner !== null) {
            $this->set($path, 0755, $owner);
        }
    }

    public function delete(string $path): void
    {
        \Forelse\DockerSim\State\Store::removeTree($this->real($path));
        foreach ($this->meta as $scope => $entries) {
            foreach (array_keys($entries) as $entry) {
                $absolute = $scope === '' ? $entry : rtrim($scope, '/').($entry === '/' ? '' : $entry);
                if (Path::isUnder($absolute, $path)) {
                    unset($this->meta[$scope][$entry]);
                }
            }
        }
        foreach (array_keys($this->meta) as $scope) {
            $this->saveMeta((string) $scope);
        }
    }

    public function list(string $path): array
    {
        $real = $this->real($path);
        $names = is_dir($real) ? array_values(array_diff(scandir($real) ?: [], ['.', '..'])) : [];
        // Un point de montage apparaît même si le dossier n'existe pas encore dans la racine.
        foreach (array_keys($this->mounts) as $target) {
            if (\dirname($target) === $path && !\in_array(basename($target), $names, true)) {
                $names[] = basename($target);
            }
        }
        sort($names);

        return $names;
    }

    public function size(string $path): int
    {
        $real = $this->real($path);
        if (is_file($real)) {
            $size = (int) filesize($real);
            [$territory, $key] = $this->territory($path);

            // Un fichier virtuel de l'image (un binaire) est vide sur disque, mais a une taille.
            return $size === 0 && isset($this->meta[$territory][$key][2]) ? (int) $this->meta[$territory][$key][2] : $size;
        }
        $total = 0;
        foreach ($this->files($path) as $file) {
            $total += (int) @filesize($this->real($file));
        }

        return $total;
    }

    public function mode(string $path): int
    {
        [$territory, $key] = $this->territory($path);

        return $this->meta[$territory][$key][0] ?? ($this->isDir($path) ? 0755 : 0644);
    }

    public function chmod(string $path, int $mode): void
    {
        $this->set($path, $mode, $this->owner($path));
    }

    public function owner(string $path): string
    {
        [$territory, $key] = $this->territory($path);

        return $this->meta[$territory][$key][1] ?? 'root';
    }

    public function chown(string $path, string $owner): void
    {
        $this->set($path, $this->mode($path), $owner);
    }

    private function set(string $path, int $mode, string $owner): void
    {
        [$territory, $key] = $this->territory($path);
        $this->meta[$territory][$key] = [$mode, $owner];
        $this->saveMeta($territory);
    }

    public function files(string $path = '/'): array
    {
        $result = [];
        $walk = function (string $dir) use (&$walk, &$result): void {
            foreach ($this->list($dir) as $name) {
                $child = rtrim($dir, '/').'/'.$name;
                if ($this->isDir($child)) {
                    if (\count($result) < 20000) {
                        $walk($child);
                    }
                } elseif ($this->isFile($child)) {
                    $result[] = $child;
                }
            }
        };
        if ($this->isFile($path)) {
            return [$path];
        }
        $walk($path);

        return $result;
    }

    private function touchMeta(string $path, ?int $mode, ?string $owner): void
    {
        if ($mode === null && $owner === null) {
            return;
        }
        $this->set($path, $mode ?? $this->mode($path), $owner ?? $this->owner($path));
    }

    private function saveMeta(string $territory): void
    {
        $file = $this->metaFiles[$territory] ?? '';
        if ($file !== '') {
            @mkdir(\dirname($file), 0777, true);
            file_put_contents($file, json_encode($this->meta[$territory] ?? [], \JSON_UNESCAPED_SLASHES));
        }
    }
}
