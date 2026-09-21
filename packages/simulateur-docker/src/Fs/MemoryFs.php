<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Fs;

use Forelse\DockerSim\State\Blob;

/**
 * Système de fichiers d'une image en cours de construction : l'état hérité des couches précédentes,
 * plus les changements de l'instruction en cours (qui deviendront une nouvelle couche).
 */
final class MemoryFs implements FileSystem
{
    /** @var array<string,string> chemin => blob */
    private array $files;
    /** @var array<string,true> */
    private array $dirs = ['/' => true];
    /** @var array<string,array{0:int,1:string}> */
    private array $meta;

    /** @var array<string,string> changements de la couche en cours */
    private array $changedFiles = [];
    /** @var array<string,true> */
    private array $changedDirs = [];
    /** @var array<string,array{0:int,1:string}> */
    private array $changedMeta = [];
    /** @var array<string,true> */
    private array $deleted = [];

    /**
     * @param array<string,string>                $files
     * @param array<string,array{0:int,1:string}> $meta
     * @param list<string>                        $dirs
     */
    public function __construct(array $files = [], array $meta = [], array $dirs = [])
    {
        $this->files = $files;
        $this->meta = $meta;
        foreach (array_keys($files) as $path) {
            $this->registerParents($path);
        }
        foreach ($dirs as $dir) {
            $this->dirs[$dir] = true;
            $this->registerParents($dir);
        }
        foreach (['/bin', '/etc', '/home', '/root', '/tmp', '/usr', '/usr/bin', '/usr/local', '/usr/local/bin', '/var', '/var/log', '/var/tmp', '/opt', '/srv', '/proc', '/dev'] as $dir) {
            $this->dirs[$dir] = true;
        }
    }

    /** Commence une nouvelle couche : les changements repartent de zéro. */
    public function beginLayer(): void
    {
        $this->changedFiles = [];
        $this->changedDirs = [];
        $this->changedMeta = [];
        $this->deleted = [];
    }

    /** @return array{files: array<string,string>, deleted: list<string>, meta: array<string,array{0:int,1:string}>, dirs: list<string>, size: int} */
    public function layerChanges(): array
    {
        $size = 0;
        foreach ($this->changedFiles as $blob) {
            $size += Blob::size($blob);
        }

        return ['files' => $this->changedFiles, 'deleted' => array_keys($this->deleted), 'meta' => $this->changedMeta, 'dirs' => array_keys($this->changedDirs), 'size' => $size];
    }

    public function isReadOnly(string $path): bool
    {
        return false;
    }

    public function exists(string $path): bool
    {
        return isset($this->files[$path]) || isset($this->dirs[$path]);
    }

    public function isDir(string $path): bool
    {
        return isset($this->dirs[$path]);
    }

    public function isFile(string $path): bool
    {
        return isset($this->files[$path]);
    }

    public function read(string $path): ?string
    {
        return isset($this->files[$path]) ? Blob::decode($this->files[$path]) : null;
    }

    public function blob(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }

    public function write(string $path, string $content, ?int $mode = null, ?string $owner = null): void
    {
        $this->put($path, Blob::store($content), $mode, $owner);
    }

    public function writeFromHost(string $path, string $hostPath, ?int $mode = null, ?string $owner = null): void
    {
        $this->put($path, Blob::fromHostFile($hostPath), $mode, $owner);
    }

    public function putBlob(string $path, string $blob, ?int $mode = null, ?string $owner = null): void
    {
        $this->put($path, $blob, $mode, $owner);
    }

    private function put(string $path, string $blob, ?int $mode, ?string $owner): void
    {
        if (isset($this->dirs[$path])) {
            $this->delete($path);
        }
        $this->files[$path] = $blob;
        $this->changedFiles[$path] = $blob;
        unset($this->deleted[$path]);
        $this->registerParents($path, true);
        $previous = $this->meta[$path] ?? [0644, 'root'];
        if ($mode !== null || $owner !== null || !isset($this->meta[$path])) {
            $this->meta[$path] = [$mode ?? $previous[0], $owner ?? $previous[1]];
        }
        $this->changedMeta[$path] = $this->meta[$path];
    }

    public function mkdir(string $path, ?string $owner = null): void
    {
        if (!isset($this->dirs[$path])) {
            $this->dirs[$path] = true;
            $this->changedDirs[$path] = true;
            unset($this->deleted[$path]);
        }
        if ($owner !== null) {
            $this->meta[$path] = [0755, $owner];
            $this->changedMeta[$path] = $this->meta[$path];
            $this->changedDirs[$path] = true;
        }
        $this->registerParents($path, true);
    }

    public function delete(string $path): void
    {
        $prefix = rtrim($path, '/').'/';
        foreach (array_keys($this->files) as $file) {
            if ($file === $path || str_starts_with($file, $prefix)) {
                unset($this->files[$file], $this->changedFiles[$file], $this->meta[$file], $this->changedMeta[$file]);
            }
        }
        foreach (array_keys($this->dirs) as $dir) {
            if ($dir !== '/' && ($dir === $path || str_starts_with($dir, $prefix))) {
                unset($this->dirs[$dir], $this->changedDirs[$dir]);
            }
        }
        $this->deleted[$path] = true;
    }

    public function list(string $path): array
    {
        $prefix = rtrim($path, '/').'/';
        $names = [];
        foreach ([...array_keys($this->files), ...array_keys($this->dirs)] as $entry) {
            if ($entry !== $path && str_starts_with($entry, $prefix)) {
                $names[explode('/', substr($entry, \strlen($prefix)))[0]] = true;
            }
        }
        $names = array_keys($names);
        sort($names);

        return array_values(array_filter($names, static fn ($n) => $n !== ''));
    }

    public function size(string $path): int
    {
        if (isset($this->files[$path])) {
            return Blob::size($this->files[$path]);
        }
        $total = 0;
        foreach ($this->files($path) as $file) {
            $total += Blob::size($this->files[$file]);
        }

        return $total;
    }

    public function mode(string $path): int
    {
        return $this->meta[$path][0] ?? (isset($this->dirs[$path]) ? 0755 : 0644);
    }

    public function chmod(string $path, int $mode): void
    {
        $this->meta[$path] = [$mode, $this->owner($path)];
        $this->changedMeta[$path] = $this->meta[$path];
        if (isset($this->files[$path])) {
            $this->changedFiles[$path] = $this->files[$path];
        }
    }

    public function owner(string $path): string
    {
        return $this->meta[$path][1] ?? 'root';
    }

    public function chown(string $path, string $owner): void
    {
        $this->meta[$path] = [$this->mode($path), $owner];
        $this->changedMeta[$path] = $this->meta[$path];
        if (isset($this->files[$path])) {
            $this->changedFiles[$path] = $this->files[$path];
        }
    }

    public function files(string $path = '/'): array
    {
        $prefix = rtrim($path, '/').'/';
        $result = [];
        foreach (array_keys($this->files) as $file) {
            if ($path === '/' || $file === $path || str_starts_with($file, $prefix)) {
                $result[] = $file;
            }
        }
        sort($result);

        return $result;
    }

    /** @return array<string,string> */
    public function allBlobs(): array
    {
        return $this->files;
    }

    private function registerParents(string $path, bool $track = false): void
    {
        $dir = \dirname($path);
        while ($dir !== '/' && $dir !== '.' && !isset($this->dirs[$dir])) {
            $this->dirs[$dir] = true;
            if ($track) {
                $this->changedDirs[$dir] = true;
            }
            $dir = \dirname($dir);
        }
    }
}
