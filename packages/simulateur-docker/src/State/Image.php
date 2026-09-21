<?php

declare(strict_types=1);

namespace Forelse\DockerSim\State;

/**
 * Une image locale (téléchargée ou construite) : ses couches, sa configuration, ses tags.
 * Les images construites gardent la trace de ce que le simulateur doit savoir pour les lancer :
 * image de base (son « type » : apache-php, nginx…), paquets, extensions PHP, binaires.
 */
final class Image
{
    /**
     * @param list<Layer>  $layers
     * @param list<string> $tags          « app:latest »
     * @param list<string> $packages      paquets apk/apt installés
     * @param list<string> $phpExtensions extensions visibles dans php -m
     * @param list<string> $binaries      commandes disponibles
     * @param list<string> $apacheModules modules activés (a2enmod)
     * @param array<string,int> $users    utilisateurs connus (/etc/passwd) => uid
     */
    public function __construct(
        public readonly string $id,
        public array $tags,
        public array $layers,
        public ImageConfig $config,
        public readonly string $base,
        public readonly string $kind,
        public readonly string $os,
        public array $packages,
        public array $phpExtensions,
        public array $binaries,
        public array $apacheModules,
        public readonly ?string $phpVersion,
        public readonly ?string $docroot,
        public readonly int $createdAt,
        /** true : image du catalogue (docker pull) ; false : construite localement. */
        public readonly bool $pulled = false,
        /** Étape du Dockerfile qui l'a produite (--target), pour docker history. */
        public readonly ?string $stage = null,
        public array $users = ['root' => 0],
    ) {
    }

    public function shortId(): string
    {
        return substr($this->id, 7, 12);
    }

    public function size(): int
    {
        return array_sum(array_map(static fn (Layer $l) => $l->size, $this->layers));
    }

    /**
     * Le système de fichiers vu depuis l'image : couches empilées dans l'ordre, suppressions comprises.
     *
     * @return array<string,string>
     */
    public function filesystem(): array
    {
        $fs = [];
        foreach ($this->layers as $layer) {
            foreach ($layer->deleted as $path) {
                foreach (array_keys($fs) as $existing) {
                    if ($existing === $path || str_starts_with($existing, rtrim($path, '/').'/')) {
                        unset($fs[$existing]);
                    }
                }
            }
            foreach ($layer->files as $path => $content) {
                $fs[$path] = $content;
            }
        }

        return $fs;
    }

    /**
     * Mode et propriétaire des fichiers, couches empilées.
     *
     * @return array<string,array{0:int,1:string}>
     */
    public function metadata(): array
    {
        $meta = [];
        foreach ($this->layers as $layer) {
            foreach ($layer->deleted as $path) {
                foreach (array_keys($meta) as $existing) {
                    if ($existing === $path || str_starts_with($existing, rtrim($path, '/').'/')) {
                        unset($meta[$existing]);
                    }
                }
            }
            foreach ($layer->meta as $path => $entry) {
                $meta[$path] = $entry;
            }
        }

        return $meta;
    }

    /** @return list<string> */
    public function directories(): array
    {
        $dirs = [];
        foreach ($this->layers as $layer) {
            foreach ($layer->deleted as $path) {
                $dirs = array_filter($dirs, static fn (string $d) => $d !== $path && !str_starts_with($d, rtrim($path, '/').'/'));
            }
            foreach ($layer->dirs as $dir) {
                $dirs[] = $dir;
            }
        }

        return array_values(array_unique($dirs));
    }

    public function hasBinary(string $name): bool
    {
        return \in_array(basename($name), $this->binaries, true);
    }

    public function hasPhpExtension(string $name): bool
    {
        foreach ($this->phpExtensions as $extension) {
            if (strcasecmp($extension, $name) === 0 || (strcasecmp($name, 'opcache') === 0 && $extension === 'Zend OPcache')) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $data = get_object_vars($this);
        $data['layers'] = array_map(static fn (Layer $l) => $l->toArray(), $this->layers);
        $data['config'] = $this->config->toArray();

        return $data;
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'], $data['tags'], array_map(Layer::fromArray(...), $data['layers']), ImageConfig::fromArray($data['config']),
            $data['base'], $data['kind'], $data['os'], $data['packages'], $data['phpExtensions'], $data['binaries'], $data['apacheModules'],
            $data['phpVersion'], $data['docroot'], $data['createdAt'], $data['pulled'] ?? false, $data['stage'] ?? null, $data['users'] ?? ['root' => 0],
        );
    }
}
