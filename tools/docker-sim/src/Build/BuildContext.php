<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Build;

use Forelse\DockerSim\Context\DockerIgnore;

/**
 * Le contexte de build : les fichiers du dossier envoyés au démon, moins ceux du .dockerignore.
 * Un fichier exclu n'existe pas pour COPY (« not found »).
 */
final class BuildContext
{
    /** @var array<string,int>|null chemin relatif => taille */
    private ?array $files = null;
    public readonly DockerIgnore $ignore;

    public function __construct(public readonly string $directory, ?string $dockerfile = null)
    {
        // Un « Dockerfile.dockerignore » à côté du Dockerfile l'emporte sur le .dockerignore du contexte.
        $specific = $dockerfile !== null ? $dockerfile.'.dockerignore' : null;
        $this->ignore = DockerIgnore::fromFile($specific !== null && is_file($specific) ? $specific : rtrim($directory, '/').'/.dockerignore');
    }

    /** @return array<string,int> */
    public function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }
        $this->files = [];
        $root = rtrim($this->directory, '/');
        $walk = function (string $dir, string $relative) use (&$walk, $root): void {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $relative === '' ? $entry : $relative.'/'.$entry;
                $absolute = $dir.'/'.$entry;
                if (is_dir($absolute) && !is_link($absolute)) {
                    // Un dossier exclu n'est pas parcouru, sauf si une exception « ! » peut y ramener un fichier.
                    if ($this->ignore->ignores($path) && !$this->mayReinclude($path)) {
                        continue;
                    }
                    $walk($absolute, $path);
                    continue;
                }
                if (!$this->ignore->ignores($path)) {
                    $this->files[$path] = (int) @filesize($absolute);
                }
            }
        };
        if (is_dir($root)) {
            $walk($root, '');
        }
        ksort($this->files);

        return $this->files;
    }

    private function mayReinclude(string $dir): bool
    {
        $content = @file_get_contents(rtrim($this->directory, '/').'/.dockerignore') ?: '';

        return (bool) preg_match('/^!\s*'.preg_quote($dir, '/').'\//m', $content);
    }

    public function size(): int
    {
        return array_sum($this->files());
    }

    public function has(string $relative): bool
    {
        return isset($this->files()[$relative]);
    }

    public function hasDirectory(string $relative): bool
    {
        $prefix = rtrim($relative, '/').'/';
        foreach (array_keys($this->files()) as $file) {
            if (str_starts_with($file, $prefix)) {
                return true;
            }
        }

        return $relative === '' || $relative === '.';
    }

    public function absolute(string $relative): string
    {
        return rtrim($this->directory, '/').'/'.$relative;
    }

    /**
     * Fichiers correspondant à une source de COPY (fichier, dossier, motif), relatifs au contexte.
     *
     * @return array<string,string>|null source relative => chemin relatif sous la destination ; null si rien ne correspond
     */
    public function match(string $source): ?array
    {
        $source = trim(preg_replace('#^\./#', '', $source) ?? $source, '/');
        if (str_starts_with($source, '..')) {
            return null;
        }
        if ($source === '' || $source === '.') {
            $result = [];
            foreach (array_keys($this->files()) as $file) {
                $result[$file] = $file;
            }

            return $result;
        }
        if (strpbrk($source, '*?[') !== false) {
            $result = [];
            $regex = '#^'.str_replace(['\*', '\?'], ['[^/]*', '[^/]'], preg_quote($source, '#')).'(/.*)?$#';
            foreach (array_keys($this->files()) as $file) {
                if (preg_match($regex, $file, $m)) {
                    // Un motif qui désigne un dossier copie son contenu sous le nom du dossier ? Non : comme Docker,
                    // chaque correspondance est copiée à la racine de la destination.
                    $matchedTop = substr($file, 0, \strlen($file) - \strlen($m[1] ?? ''));
                    $result[$file] = basename($matchedTop).($m[1] ?? '');
                    if (!isset($m[1])) {
                        $result[$file] = basename($file);
                    }
                }
            }

            return $result === [] ? null : $result;
        }
        if ($this->has($source)) {
            return [$source => basename($source)];
        }
        if ($this->hasDirectory($source)) {
            $result = [];
            $prefix = $source.'/';
            foreach (array_keys($this->files()) as $file) {
                if (str_starts_with($file, $prefix)) {
                    $result[$file] = substr($file, \strlen($prefix));
                }
            }

            return $result;
        }

        return null;
    }

    /** Empreinte d'un ensemble de fichiers (clé de cache d'un COPY). @param list<string> $files */
    public function checksum(array $files): string
    {
        $hash = hash_init('xxh128');
        foreach ($files as $file) {
            hash_update($hash, $file."\0");
            $absolute = $this->absolute($file);
            hash_update($hash, (string) (@filesize($absolute)).':'.(@hash_file('xxh128', $absolute) ?: '')."\0");
        }

        return hash_final($hash);
    }
}
