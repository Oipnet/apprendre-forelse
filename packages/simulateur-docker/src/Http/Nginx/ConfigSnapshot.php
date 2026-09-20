<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Http\Nginx;

use Forelse\DockerSim\Fs\FileSystem;
use Forelse\DockerSim\Fs\Path;

/**
 * La configuration que nginx a lue au démarrage (ou au dernier « nginx -s reload ») : nginx ne relit
 * pas ses fichiers à chaque requête. Modifier default.conf ne change rien tant qu'on ne recharge pas.
 * Lecture seule : les fichiers de /etc/nginx viennent de l'instantané, le reste du disque du conteneur.
 */
final class ConfigSnapshot implements FileSystem
{
    private const ROOT = '/etc/nginx';

    /** @param array<string,string> $files chemin => contenu, pour tout ce qui vivait sous /etc/nginx */
    public function __construct(private readonly FileSystem $fs, private readonly array $files)
    {
    }

    /** @return array<string,string> */
    public static function take(FileSystem $fs): array
    {
        $files = [];
        foreach ($fs->isDir(self::ROOT) ? $fs->files(self::ROOT) : [] as $file) {
            $content = $fs->read($file);
            if ($content !== null && \strlen($content) < 200_000) {
                $files[$file] = $content;
            }
        }
        ksort($files);

        return $files;
    }

    private function covers(string $path): bool
    {
        return Path::isUnder($path, self::ROOT);
    }

    public function exists(string $path): bool
    {
        return $this->covers($path) ? $this->isFile($path) || $this->isDir($path) : $this->fs->exists($path);
    }

    public function isReadOnly(string $path): bool
    {
        return $this->fs->isReadOnly($path);
    }

    public function isDir(string $path): bool
    {
        if (!$this->covers($path)) {
            return $this->fs->isDir($path);
        }
        $prefix = rtrim($path, '/').'/';
        foreach (array_keys($this->files) as $file) {
            if (str_starts_with($file, $prefix)) {
                return true;
            }
        }

        return rtrim($path, '/') === self::ROOT;
    }

    public function isFile(string $path): bool
    {
        return $this->covers($path) ? isset($this->files[$path]) : $this->fs->isFile($path);
    }

    public function read(string $path): ?string
    {
        return $this->covers($path) ? ($this->files[$path] ?? null) : $this->fs->read($path);
    }

    public function list(string $path): array
    {
        if (!$this->covers($path)) {
            return $this->fs->list($path);
        }
        $prefix = rtrim($path, '/').'/';
        $names = [];
        foreach (array_keys($this->files) as $file) {
            if (str_starts_with($file, $prefix)) {
                $names[explode('/', substr($file, \strlen($prefix)))[0]] = true;
            }
        }
        $names = array_keys($names);
        sort($names);

        return $names;
    }

    public function files(string $path = '/'): array
    {
        return $this->covers($path) ? array_values(array_filter(array_keys($this->files), static fn ($f) => Path::isUnder($f, $path))) : $this->fs->files($path);
    }

    public function size(string $path): int
    {
        return $this->covers($path) ? \strlen($this->files[$path] ?? '') : $this->fs->size($path);
    }

    public function mode(string $path): int
    {
        return $this->fs->mode($path);
    }

    public function owner(string $path): string
    {
        return $this->fs->owner($path);
    }

    // L'instantané ne s'écrit pas : nginx lit sa configuration, il ne la modifie pas.
    public function write(string $path, string $content, ?int $mode = null, ?string $owner = null): void
    {
        throw new \LogicException('La configuration chargée par nginx est en lecture seule.');
    }

    public function writeFromHost(string $path, string $hostPath, ?int $mode = null, ?string $owner = null): void
    {
        throw new \LogicException('La configuration chargée par nginx est en lecture seule.');
    }

    public function mkdir(string $path, ?string $owner = null): void
    {
        throw new \LogicException('La configuration chargée par nginx est en lecture seule.');
    }

    public function delete(string $path): void
    {
        throw new \LogicException('La configuration chargée par nginx est en lecture seule.');
    }

    public function chmod(string $path, int $mode): void
    {
        throw new \LogicException('La configuration chargée par nginx est en lecture seule.');
    }

    public function chown(string $path, string $owner): void
    {
        throw new \LogicException('La configuration chargée par nginx est en lecture seule.');
    }
}
