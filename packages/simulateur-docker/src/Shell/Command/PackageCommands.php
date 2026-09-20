<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell\Command;

use Forelse\DockerSim\Catalog\Packages;
use Forelse\DockerSim\Fs\MemoryFs;
use Forelse\DockerSim\Shell\Interpreter;
use Forelse\DockerSim\Shell\Machine;
use Forelse\DockerSim\Shell\Result;
use Forelse\DockerSim\State\Blob;

/**
 * Gestionnaires de paquets : apk (Alpine) et apt-get (Debian). Chaque paquet installé laisse des
 * fichiers « virtuels » à sa taille réelle : ils pèsent dans la couche, et une suppression dans
 * une instruction suivante ne rend pas la place (comme avec de vraies couches).
 */
final class PackageCommands implements Command
{
    private const APT_LISTS = '/var/lib/apt/lists';
    private const APK_CACHE = '/var/cache/apk';

    public function names(): array
    {
        return ['apk', 'apt-get', 'apt', 'dpkg', 'apt-cache'];
    }

    public function run(string $name, array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        return match ($name) {
            'apk' => $this->apk($args, $m),
            'apt-get', 'apt' => $this->apt($name, $args, $m),
            'dpkg' => \in_array('-l', $args, true) ? Result::ok($this->dpkgList($m)) : Result::ok(),
            'apt-cache' => Result::ok(),
            default => Result::ok(),
        };
    }

    // --- apk ------------------------------------------------------------------------------

    private function apk(array $args, Machine $m): Result
    {
        $subcommand = null;
        $options = [];
        $operands = [];
        for ($i = 0; $i < \count($args); ++$i) {
            $arg = $args[$i];
            if (\in_array($arg, ['--virtual', '-t'], true)) {
                $options['virtual'] = $args[++$i] ?? '';
            } elseif (str_starts_with($arg, '--virtual=')) {
                $options['virtual'] = substr($arg, 10);
            } elseif (str_starts_with($arg, '-')) {
                $options[ltrim($arg, '-')] = true;
            } elseif ($subcommand === null) {
                $subcommand = $arg;
            } else {
                $operands[] = $arg;
            }
        }
        if ($subcommand === null) {
            return Result::ok("apk-tools 2.14.9, compiled for x86_64.\n\nusage: apk [<OPTIONS>...] COMMAND [<ARGUMENTS>...]\n");
        }
        if (\in_array($subcommand, ['add', 'del', 'update', 'upgrade'], true) && !$m->isRoot()) {
            return Result::error(99, "ERROR: Unable to lock database: Permission denied\nERROR: Failed to open apk database: Permission denied\n");
        }
        $noCache = isset($options['no-cache']);

        switch ($subcommand) {
            case 'update':
                $this->writeApkIndex($m);

                return Result::ok("fetch https://dl-cdn.alpinelinux.org/alpine/v3.22/main/x86_64/APKINDEX.tar.gz\nfetch https://dl-cdn.alpinelinux.org/alpine/v3.22/community/x86_64/APKINDEX.tar.gz\nv3.22.1-67-g2b37e4d3f7e [https://dl-cdn.alpinelinux.org/alpine/v3.22/main]\nOK: 26326 distinct packages available\n", 1.4);
            case 'upgrade':
                return Result::ok("OK: 12 MiB in 17 packages\n", 2.0);
            case 'add':
                return $this->apkAdd($operands, $options['virtual'] ?? null, $noCache, $m);
            case 'del':
                return $this->remove($operands, $m, alpine: true);
            case 'info':
            case 'list':
                return Result::ok(implode("\n", $m->facts->packageNames())."\n");
            default:
                return Result::error(1, "apk: unknown command '{$subcommand}'\n");
        }
    }

    /** @param list<string> $names */
    private function apkAdd(array $names, ?string $virtual, bool $noCache, Machine $m): Result
    {
        if ($names === [] && $virtual === null) {
            return Result::ok('', 0.1);
        }
        $out = '';
        $unknown = [];
        foreach ($names as $name) {
            $name = preg_replace('/[=<>~].*$/', '', $name) ?? $name;
            if (Packages::find('alpine', $name) === null) {
                $unknown[] = $name;
            }
        }
        if (!$noCache || !$m->fs->exists(self::APK_CACHE.'/APKINDEX.tar.gz')) {
            $out .= "fetch https://dl-cdn.alpinelinux.org/alpine/v3.22/main/x86_64/APKINDEX.tar.gz\nfetch https://dl-cdn.alpinelinux.org/alpine/v3.22/community/x86_64/APKINDEX.tar.gz\n";
        }
        if ($unknown !== []) {
            $err = '';
            foreach ($unknown as $name) {
                $suggestion = Packages::closest('alpine', $name);
                $err .= "ERROR: unable to select packages:\n  {$name} (no such package):\n    required by: world[{$name}]\n";
                if ($suggestion !== null) {
                    $m->note(sprintf('Le paquet Alpine le plus proche de « %s » est « %s ».', $name, $suggestion));
                }
            }

            return Result::error(\count($unknown), $err, $out, 1.2);
        }
        if (!$noCache) {
            $this->writeApkIndex($m);
        }
        $toInstall = [];
        foreach ($names as $name) {
            $name = preg_replace('/[=<>~].*$/', '', $name) ?? $name;
            foreach ([$name, ...Packages::dependencies('alpine', $name)] as $index => $package) {
                if (!$m->facts->hasPackage($package) && !isset($toInstall[$package])) {
                    $toInstall[$package] = $index === 0;
                } elseif ($index === 0 && $m->facts->hasPackage($package)) {
                    // Réinstallé explicitement : il sort du paquet virtuel et survivra au « apk del ».
                    $m->facts->packages[$package] = ['explicit' => true, 'virtual' => null];
                }
            }
        }
        $total = \count($toInstall) + ($virtual !== null ? 1 : 0);
        $step = 0;
        $size = 0;
        foreach ($toInstall as $package => $explicit) {
            $info = Packages::find('alpine', $package) ?? ['size' => 1];
            ++$step;
            $out .= sprintf("(%d/%d) Installing %s (%s)\n", $step, $total, $package, $this->fakeVersion($package));
            $this->install($m, $package, $info, $explicit && $virtual === null, $virtual);
            $size += $info['size'];
        }
        if ($virtual !== null) {
            ++$step;
            $out .= sprintf("(%d/%d) Installing %s (20250915.120000)\n", $step, $total, $virtual);
            $m->facts->packages[$virtual] = ['explicit' => true, 'virtual' => null];
        }
        if ($toInstall !== []) {
            $out .= "Executing busybox-1.37.0-r18.trigger\n";
        }
        $out .= sprintf("OK: %d MiB in %d packages\n", 12 + $size + \count($m->facts->packages), 17 + \count($m->facts->packages));

        return Result::ok($out, 0.8 + 0.25 * \count($toInstall) + $size / 60);
    }

    private function writeApkIndex(Machine $m): void
    {
        $this->virtualFile($m, self::APK_CACHE.'/APKINDEX.5e3b8a1c.tar.gz', 1_400_000);
        $this->virtualFile($m, self::APK_CACHE.'/APKINDEX.8f2a6b9d.tar.gz', 900_000);
    }

    // --- apt ------------------------------------------------------------------------------

    private function apt(string $binary, array $args, Machine $m): Result
    {
        $subcommand = null;
        $options = [];
        $operands = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '-')) {
                $options[ltrim($arg, '-')] = true;
            } elseif ($subcommand === null) {
                $subcommand = $arg;
            } else {
                $operands[] = $arg;
            }
        }
        $yes = isset($options['y']) || isset($options['yes']) || isset($options['qy']) || isset($options['yq']) || isset($options['assume-yes']) || ($m->env['DEBIAN_FRONTEND'] ?? '') === 'noninteractive' && isset($options['qq']);
        if ($subcommand === null) {
            return Result::ok("apt 3.0.3 (amd64)\nUsage: apt-get [options] command\n");
        }
        if (\in_array($subcommand, ['update', 'install', 'remove', 'purge', 'autoremove', 'upgrade', 'dist-upgrade', 'clean'], true) && !$m->isRoot()) {
            return $subcommand === 'update'
                ? Result::error(100, "Reading package lists...\nE: Could not open lock file /var/lib/apt/lists/lock - open (13: Permission denied)\nE: Unable to lock directory /var/lib/apt/lists/\n")
                : Result::error(100, "E: Could not open lock file /var/lib/dpkg/lock-frontend - open (13: Permission denied)\nE: Unable to acquire the dpkg frontend lock (/var/lib/dpkg/lock-frontend), are you root?\n");
        }
        $prefix = $binary === 'apt' ? "\nWARNING: apt does not have a stable CLI interface. Use with caution in scripts.\n\n" : '';

        switch ($subcommand) {
            case 'update':
                $this->virtualFile($m, self::APT_LISTS.'/deb.debian.org_debian_dists_trixie_InRelease', 150_000);
                $this->virtualFile($m, self::APT_LISTS.'/deb.debian.org_debian_dists_trixie_main_binary-amd64_Packages.lz4', 19_800_000);
                $this->virtualFile($m, self::APT_LISTS.'/deb.debian.org_debian-security_dists_trixie-security_main_binary-amd64_Packages.lz4', 600_000);

                return Result::ok($prefix."Get:1 http://deb.debian.org/debian trixie InRelease [140 kB]\nGet:2 http://deb.debian.org/debian trixie-updates InRelease [47.3 kB]\nGet:3 http://deb.debian.org/debian-security trixie-security InRelease [43.4 kB]\nGet:4 http://deb.debian.org/debian trixie/main amd64 Packages [9670 kB]\nFetched 9901 kB in 2s (4950 kB/s)\nReading package lists...\n", 3.8);
            case 'install':
                return $this->aptInstall($operands, $yes, $options, $m, $prefix);
            case 'remove':
            case 'purge':
                $result = $this->remove($operands, $m, alpine: false, autoRemove: isset($options['auto-remove']));

                return new Result($result->code, $prefix."Reading package lists...\nBuilding dependency tree...\n".$result->stdout, $result->stderr, $result->seconds);
            case 'autoremove':
                return Result::ok($prefix."Reading package lists...\nBuilding dependency tree...\n0 upgraded, 0 newly installed, 0 to remove and 0 not upgraded.\n", 0.6);
            case 'clean':
            case 'autoclean':
                $m->fs->delete('/var/cache/apt/archives');
                $m->fs->mkdir('/var/cache/apt/archives');

                return Result::ok('', 0.1);
            case 'upgrade':
            case 'dist-upgrade':
                return Result::ok($prefix."Reading package lists...\nBuilding dependency tree...\nCalculating upgrade...\n0 upgraded, 0 newly installed, 0 to remove and 0 not upgraded.\n", 1.5);
            default:
                return Result::error(100, "E: Invalid operation {$subcommand}\n");
        }
    }

    /** @param array<string,bool> $options */
    private function aptInstall(array $names, bool $yes, array $options, Machine $m, string $prefix): Result
    {
        $out = $prefix."Reading package lists...\n";
        $listsPresent = $m->fs->files(self::APT_LISTS) !== [];
        foreach ($names as $name) {
            $name = preg_replace('/=.*$/', '', $name) ?? $name;
            // Sans « apt-get update », les images officielles n'ont aucune liste de paquets.
            if (!$listsPresent || Packages::find('debian', $name) === null) {
                $out .= "Building dependency tree...\nReading state information...\n";

                return Result::error(100, "E: Unable to locate package {$name}\n", $out, 0.6);
            }
        }
        $toInstall = [];
        foreach ($names as $name) {
            $name = preg_replace('/=.*$/', '', $name) ?? $name;
            foreach ([$name, ...Packages::dependencies('debian', $name)] as $index => $package) {
                if (!$m->facts->hasPackage($package) && !isset($toInstall[$package])) {
                    $toInstall[$package] = $index === 0;
                } elseif ($index === 0) {
                    $m->facts->packages[$package]['explicit'] = true;
                }
            }
        }
        $out .= "Building dependency tree...\nReading state information...\n";
        if ($toInstall === []) {
            $out .= "0 upgraded, 0 newly installed, 0 to remove and 0 not upgraded.\n";

            return Result::ok($out, 0.8);
        }
        $size = array_sum(array_map(static fn ($p) => (Packages::find('debian', $p) ?? ['size' => 1])['size'], array_keys($toInstall)));
        // Sans --no-install-recommends, apt tire aussi les paquets « recommandés » : plus lourd.
        $recommends = !isset($options['no-install-recommends']);
        $installedSize = $recommends ? (int) round($size * 1.6) : $size;
        $out .= "The following NEW packages will be installed:\n  ".implode(' ', array_keys($toInstall))."\n";
        $out .= sprintf("0 upgraded, %d newly installed, 0 to remove and 0 not upgraded.\nNeed to get %d MB of archives.\nAfter this operation, %d MB of additional disk space will be used.\n", \count($toInstall), max(1, intdiv($installedSize, 3)), $installedSize);
        if (!$yes) {
            return Result::error(1, '', $out."Do you want to continue? [Y/n] Abort.\n", 0.8);
        }
        $index = 0;
        foreach ($toInstall as $package => $explicit) {
            ++$index;
            $out .= sprintf("Get:%d http://deb.debian.org/debian trixie/main amd64 %s amd64 %s [%d kB]\n", $index, $package, $this->fakeVersion($package), 120 * $index);
        }
        $out .= "debconf: delaying package configuration, since apt-utils is not installed\n";
        foreach ($toInstall as $package => $explicit) {
            $info = Packages::find('debian', $package) ?? ['size' => 1];
            $out .= sprintf("Setting up %s (%s) ...\n", $package, $this->fakeVersion($package));
            if ($recommends && $explicit) {
                $info['size'] = (int) round($info['size'] * 1.6);
            }
            $this->install($m, $package, $info, $explicit, null);
        }

        return Result::ok($out, 1.5 + 0.4 * \count($toInstall) + $size / 25);
    }

    // --- Commun ---------------------------------------------------------------------------

    /** @param array{bin?: list<string>, size: int} $info */
    private function install(Machine $m, string $package, array $info, bool $explicit, ?string $virtual): void
    {
        $m->facts->packages[$package] = ['explicit' => $explicit, 'virtual' => $virtual];
        $this->virtualFile($m, '/usr/share/sim-packages/'.$package, max(1, $info['size']) * 1_000_000);
        foreach ($info['bin'] ?? [] as $binary) {
            $m->facts->binaries[] = $binary;
        }
        $m->facts->binaries = array_values(array_unique($m->facts->binaries));
    }

    /** @param list<string> $names */
    private function remove(array $names, Machine $m, bool $alpine, bool $autoRemove = true): Result
    {
        $out = '';
        $removed = [];
        foreach ($names as $name) {
            if (!$m->facts->hasPackage($name)) {
                if ($alpine) {
                    return Result::error(1, "ERROR: No such package: {$name}\n");
                }
                continue;
            }
            // Un paquet virtuel (--virtual .build-deps) emporte les paquets installés avec lui.
            foreach ($m->facts->packages as $package => $info) {
                if ($package === $name || $info['virtual'] === $name) {
                    $removed[] = $package;
                }
            }
        }
        if ($alpine || $autoRemove) {
            // Les dépendances qui ne servent plus à personne partent aussi (bibliothèques comprises).
            foreach ($removed as $package) {
                foreach (Packages::dependencies($m->facts->os, $package) as $dependency) {
                    if (isset($m->facts->packages[$dependency]) && !$m->facts->packages[$dependency]['explicit'] && !$this->stillRequired($m, $dependency, $removed)) {
                        $removed[] = $dependency;
                    }
                }
            }
        }
        $removed = array_values(array_unique($removed));
        foreach ($removed as $index => $package) {
            $out .= $alpine ? sprintf("(%d/%d) Purging %s (%s)\n", $index + 1, \count($removed), $package, $this->fakeVersion($package)) : sprintf("Removing %s (%s) ...\n", $package, $this->fakeVersion($package));
            unset($m->facts->packages[$package]);
            $m->fs->delete('/usr/share/sim-packages/'.$package);
            $info = Packages::find($m->facts->os, $package);
            foreach ($info['bin'] ?? [] as $binary) {
                if (!$this->binaryProvidedElsewhere($m, $binary)) {
                    $m->facts->binaries = array_values(array_diff($m->facts->binaries, [$binary]));
                }
            }
        }
        if ($alpine) {
            $out .= sprintf("OK: %d MiB in %d packages\n", 12 + \count($m->facts->packages), 17 + \count($m->facts->packages));
        }

        return Result::ok($out, 0.4 + 0.1 * \count($removed));
    }

    /** @param list<string> $removed */
    private function stillRequired(Machine $m, string $dependency, array $removed): bool
    {
        foreach (array_keys($m->facts->packages) as $package) {
            if (!\in_array($package, $removed, true) && \in_array($dependency, Packages::dependencies($m->facts->os, $package), true)) {
                return true;
            }
        }

        return false;
    }

    private function binaryProvidedElsewhere(Machine $m, string $binary): bool
    {
        foreach (array_keys($m->facts->packages) as $package) {
            if (\in_array($binary, Packages::find($m->facts->os, $package)['bin'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    private function virtualFile(Machine $m, string $path, int $size): void
    {
        if ($m->fs instanceof MemoryFs) {
            $m->fs->putBlob($path, Blob::virtual($size));

            return;
        }
        $m->fs->write($path, '');
    }

    private function dpkgList(Machine $m): string
    {
        $out = "Desired=Unknown/Install/Remove/Purge/Hold\n||/ Name           Version      Architecture Description\n+++-==============-============-============-=================================\n";
        foreach ($m->facts->packageNames() as $package) {
            $out .= sprintf("ii  %-14s %-12s amd64        %s\n", $package, $this->fakeVersion($package), $package);
        }

        return $out;
    }

    private function fakeVersion(string $package): string
    {
        $hash = crc32($package);

        return sprintf('%d.%d.%d-r%d', 1 + $hash % 3, $hash % 17, $hash % 9, $hash % 4);
    }
}
