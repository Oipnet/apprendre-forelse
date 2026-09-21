<?php

declare(strict_types=1);

namespace Forelse\DockerSim\State;

/**
 * L'état du « démon » : images, conteneurs, réseaux, volumes, cache de build.
 * Métadonnées dans state.json ; systèmes de fichiers des conteneurs et contenu des volumes
 * dans de vrais dossiers (containers/<id>/rootfs, volumes/<nom>/_data), pour que PHP puisse
 * y exécuter le code servi par les conteneurs.
 */
final class Store
{
    /** @var array<string, Image> par id */
    public array $images = [];
    /** @var array<string, Container> par id */
    public array $containers = [];
    /** @var array<string, Network> par nom */
    public array $networks = [];
    /** @var array<string, Volume> par nom */
    public array $volumes = [];
    /** @var array<string, array<string,mixed>> clé de cache => couche sérialisée */
    public array $buildCache = [];
    public int $ipCounter = 2;

    public function __construct(public readonly string $directory)
    {
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }
        // Les contenus volumineux (fichiers copiés depuis le projet) vivent à côté de state.json.
        Blob::useDirectory($directory.'/blobs');
        $this->load();
        if (!isset($this->networks['bridge'])) {
            $this->networks['bridge'] = new Network(self::id('network-bridge'), 'bridge', 'bridge', '172.17.0.0/16', [], time(), true);
            $this->networks['host'] = new Network(self::id('network-host'), 'host', 'host', '', [], time(), true);
            $this->networks['none'] = new Network(self::id('network-none'), 'none', 'null', '', [], time(), true);
        }
    }

    public static function id(string $seed = ''): string
    {
        return hash('sha256', $seed === '' ? random_bytes(16) : $seed);
    }

    public function file(): string
    {
        return $this->directory.'/state.json';
    }

    public function rootfs(string $containerId): string
    {
        return $this->directory.'/containers/'.$containerId.'/rootfs';
    }

    public function volumePath(string $name): string
    {
        return $this->directory.'/volumes/'.$name.'/_data';
    }

    public function save(): void
    {
        $data = [
            'images' => array_map(static fn (Image $i) => $i->toArray(), $this->images),
            'containers' => array_map(static fn (Container $c) => $c->toArray(), $this->containers),
            'networks' => array_map(static fn (Network $n) => $n->toArray(), $this->networks),
            'volumes' => array_map(static fn (Volume $v) => $v->toArray(), $this->volumes),
            'buildCache' => $this->buildCache,
            'ipCounter' => $this->ipCounter,
        ];
        file_put_contents($this->file(), json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function load(): void
    {
        if (!is_file($this->file())) {
            return;
        }
        $data = json_decode((string) file_get_contents($this->file()), true);
        if (!\is_array($data)) {
            return;
        }
        foreach ($data['images'] ?? [] as $id => $image) {
            $this->images[$id] = Image::fromArray($image);
        }
        foreach ($data['containers'] ?? [] as $id => $container) {
            $this->containers[$id] = Container::fromArray($container);
        }
        foreach ($data['networks'] ?? [] as $name => $network) {
            $this->networks[$name] = Network::fromArray($network);
        }
        foreach ($data['volumes'] ?? [] as $name => $volume) {
            $this->volumes[$name] = Volume::fromArray($volume);
        }
        $this->buildCache = $data['buildCache'] ?? [];
        $this->ipCounter = $data['ipCounter'] ?? 2;
    }

    // --- Recherche ----------------------------------------------------------------------

    /** Image par référence (app, app:latest, docker.io/library/php:8.4), id ou préfixe d'id. */
    public function findImage(string $reference): ?Image
    {
        $normalized = self::normalizeTag($reference);
        foreach ($this->images as $image) {
            if (\in_array($normalized, $image->tags, true)) {
                return $image;
            }
        }
        $hex = preg_replace('/^sha256:/', '', $reference) ?? $reference;
        if (preg_match('/^[0-9a-f]{4,64}$/', $hex)) {
            foreach ($this->images as $image) {
                if (str_starts_with(substr($image->id, 7), $hex)) {
                    return $image;
                }
            }
        }

        return null;
    }

    /** « app » => « app:latest », « docker.io/library/php:8.4 » => « php:8.4 ». */
    public static function normalizeTag(string $reference): string
    {
        $reference = preg_replace('#^(docker\.io/)?(library/)?#', '', $reference) ?? $reference;
        $slash = strrpos($reference, '/');
        $colon = strrpos($reference, ':');
        if ($colon === false || ($slash !== false && $colon < $slash)) {
            $reference .= ':latest';
        }

        return $reference;
    }

    public function findContainer(string $nameOrId): ?Container
    {
        $nameOrId = ltrim($nameOrId, '/');
        foreach ($this->containers as $container) {
            if ($container->name === $nameOrId || $container->id === $nameOrId) {
                return $container;
            }
        }
        if (preg_match('/^[0-9a-f]{3,64}$/', $nameOrId)) {
            $matches = array_values(array_filter($this->containers, static fn (Container $c) => str_starts_with($c->id, $nameOrId)));
            if (\count($matches) === 1) {
                return $matches[0];
            }
        }

        return null;
    }

    public function removeImage(Image $image): void
    {
        unset($this->images[$image->id]);
    }

    public function removeContainer(Container $container): void
    {
        unset($this->containers[$container->id]);
        self::removeTree(\dirname($this->rootfs($container->id)));
    }

    public function removeVolume(string $name): void
    {
        unset($this->volumes[$name]);
        self::removeTree(\dirname($this->volumePath($name)));
    }

    /** Comme Docker : chaque réseau distribue ses adresses à partir de .2 (.1 est la passerelle), la plus basse libre d'abord. */
    public function nextIp(string $network): string
    {
        $subnet = $this->networks[$network]->subnet ?? '172.18.0.0/16';
        $base = explode('/', $subnet)[0];
        $prefix = implode('.', \array_slice(explode('.', $base), 0, 3));
        $taken = [];
        foreach ($this->containers as $container) {
            if (isset($container->ips[$network])) {
                $taken[$container->ips[$network]] = true;
            }
        }
        for ($host = 2; $host < 255; ++$host) {
            if (!isset($taken[$prefix.'.'.$host])) {
                return $prefix.'.'.$host;
            }
        }

        return $prefix.'.'.($this->ipCounter++ % 250 + 2);
    }

    public static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path.'/'.$entry);
            }
        }
        @rmdir($path);
    }

    public static function copyTree(string $source, string $target): void
    {
        if (is_file($source)) {
            @mkdir(\dirname($target), 0777, true);
            @copy($source, $target);

            return;
        }
        if (!is_dir($source)) {
            return;
        }
        @mkdir($target, 0777, true);
        foreach (scandir($source) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::copyTree($source.'/'.$entry, $target.'/'.$entry);
            }
        }
    }
}
