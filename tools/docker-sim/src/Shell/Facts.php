<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell;

use Forelse\DockerSim\Catalog\PhpExtensions;
use Forelse\DockerSim\State\Image;

/**
 * Ce que le simulateur sait d'un système en cours de construction ou d'exécution, au-delà de ses
 * fichiers : paquets installés (explicitement ou comme dépendances), extensions PHP, binaires,
 * modules Apache, utilisateurs.
 */
final class Facts
{
    /**
     * @param array<string, array{explicit: bool, virtual: ?string}> $packages
     * @param list<string>      $phpExtensions  compilées et activées
     * @param list<string>      $peclBuilt      compilées par pecl mais pas encore activées
     * @param list<string>      $binaries
     * @param list<string>      $apacheModules
     * @param array<string,int> $users
     */
    public function __construct(
        public string $os,
        public string $kind,
        public ?string $phpVersion,
        public array $packages = [],
        public array $phpExtensions = [],
        public array $peclBuilt = [],
        public array $binaries = [],
        public array $apacheModules = [],
        public array $users = ['root' => 0],
    ) {
    }

    /** Le numéro d'API de PHP (phpize, dossier des extensions) : il change à chaque version mineure. */
    public static function phpApi(?string $version): string
    {
        return match (true) {
            $version === null => '20240924',
            str_starts_with($version, '8.5') => '20250925',
            str_starts_with($version, '8.4') => '20240924',
            str_starts_with($version, '8.3') => '20230831',
            str_starts_with($version, '8.2') => '20220829',
            str_starts_with($version, '8.1') => '20210902',
            str_starts_with($version, '8.0') => '20200930',
            str_starts_with($version, '7.4') => '20190902',
            default => '20240924',
        };
    }

    public static function extensionDir(?string $version): string
    {
        return '/usr/local/lib/php/extensions/no-debug-non-zts-'.self::phpApi($version);
    }

    public static function fromImage(Image $image): self
    {
        $packages = [];
        foreach ($image->packages as $entry) {
            // Format rangé dans l'image : « nom » (explicite) ou « nom@virtuel » / « nom~ » (dépendance).
            if (preg_match('/^([^@~]+)(~)?(?:@(.+))?$/', $entry, $m)) {
                $packages[$m[1]] = ['explicit' => ($m[2] ?? '') === '', 'virtual' => $m[3] ?? null];
            }
        }
        $pecl = array_values(array_filter($image->phpExtensions, static fn ($e) => str_starts_with($e, 'pecl:')));
        $enabled = array_values(array_filter($image->phpExtensions, static fn ($e) => !str_starts_with($e, 'pecl:')));

        return new self($image->os, $image->kind, $image->phpVersion, $packages, $enabled, array_map(static fn ($e) => substr($e, 5), $pecl), $image->binaries, $image->apacheModules, $image->users);
    }

    /** @return array{packages: list<string>, phpExtensions: list<string>, binaries: list<string>, apacheModules: list<string>, users: array<string,int>} */
    public function export(): array
    {
        $packages = [];
        foreach ($this->packages as $name => $info) {
            $packages[] = $name.($info['explicit'] ? '' : '~').($info['virtual'] !== null ? '@'.$info['virtual'] : '');
        }

        return [
            'packages' => $packages,
            'phpExtensions' => [...$this->phpExtensions, ...array_map(static fn ($e) => 'pecl:'.$e, $this->peclBuilt)],
            'binaries' => array_values(array_unique($this->binaries)),
            'apacheModules' => array_values(array_unique($this->apacheModules)),
            'users' => $this->users,
        ];
    }

    public function hasPackage(string $name): bool
    {
        return isset($this->packages[$name]);
    }

    /** @return list<string> */
    public function packageNames(): array
    {
        return array_keys($this->packages);
    }

    public function hasBinary(string $name): bool
    {
        return \in_array(basename($name), $this->binaries, true);
    }

    public function isPhpImage(): bool
    {
        return $this->phpVersion !== null;
    }

    /**
     * Extensions réellement chargées (php -m) et avertissements de démarrage pour celles dont
     * la bibliothèque partagée a disparu.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public function loadedExtensions(): array
    {
        $loaded = [];
        $warnings = [];
        foreach ($this->phpExtensions as $extension) {
            $runtime = PhpExtensions::RUNTIME[strtolower($extension)] ?? null;
            $lib = $runtime[$this->os === 'alpine' ? 'alpine' : 'debian'] ?? '';
            if ($runtime !== null && $lib !== '' && !\in_array(strtolower($extension), array_map('strtolower', \Forelse\DockerSim\Catalog\PhpExtensions::BUILTIN), true) && !$this->hasPackage($lib) && !$this->hasPackageLike($lib)) {
                $warnings[] = sprintf("PHP Warning:  PHP Startup: Unable to load dynamic library '%s' (tried: ".self::extensionDir($this->phpVersion)."/%s (Error loading shared library %s: No such file or directory (needed by ".self::extensionDir($this->phpVersion)."/%s.so)), ".self::extensionDir($this->phpVersion)."/%s.so (Error loading shared library %s: No such file or directory)) in Unknown on line 0", strtolower($extension), strtolower($extension), $runtime['so'], strtolower($extension), strtolower($extension), $runtime['so']);
                continue;
            }
            $loaded[] = PhpExtensions::moduleName($extension);
        }

        return [$loaded, $warnings];
    }

    private function hasPackageLike(string $lib): bool
    {
        // Sur les images php, les bibliothèques des extensions compilées d'origine sont présentes.
        foreach (array_keys($this->packages) as $name) {
            if (str_starts_with($name, $lib)) {
                return true;
            }
        }

        return false;
    }
}
