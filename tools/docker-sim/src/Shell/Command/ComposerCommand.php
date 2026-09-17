<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell\Command;

use Forelse\DockerSim\Fs\MemoryFs;
use Forelse\DockerSim\Shell\Interpreter;
use Forelse\DockerSim\Shell\Machine;
use Forelse\DockerSim\Shell\Result;

/**
 * Composer : vérifie les exigences de plateforme (version de PHP, extensions), installe les paquets
 * du composer.lock en recopiant ceux présents dans le vendor/ de l'hôte, et génère un vrai
 * vendor/autoload.php (PSR-4 + files), exécutable par le PHP des conteneurs.
 */
final class ComposerCommand implements Command
{
    public const VERSION = '2.8.10';

    public function names(): array
    {
        return ['composer'];
    }

    public function run(string $name, array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        $options = [];
        $operands = [];
        $workingDir = null;
        for ($i = 0; $i < \count($args); ++$i) {
            $arg = $args[$i];
            if (\in_array($arg, ['-d', '--working-dir'], true)) {
                $workingDir = $args[++$i] ?? null;
            } elseif (str_starts_with($arg, '--working-dir=')) {
                $workingDir = substr($arg, 14);
            } elseif (str_starts_with($arg, '-')) {
                foreach (str_starts_with($arg, '--') ? [substr($arg, 2)] : str_split(substr($arg, 1)) as $flag) {
                    $options[explode('=', $flag)[0]] = true;
                    // --ignore-platform-req=ext-intl (répétable) : seules ces exigences sont ignorées.
                    if (str_starts_with($flag, 'ignore-platform-req=')) {
                        $options['ignored-requirements'][] = substr($flag, 20);
                    }
                }
            } else {
                $operands[] = $arg;
            }
        }
        $command = $operands[0] ?? null;
        if (isset($options['version']) || isset($options['V']) || $command === '--version') {
            return Result::ok('Composer version '.self::VERSION." 2025-07-10 19:08:33\nPHP version ".($m->facts->phpVersion ?? '8.4.11')." (/usr/local/bin/php)\nRun the \"diagnose\" command to get more detailed diagnostics output.\n");
        }
        if (!$m->facts->isPhpImage() && !$m->facts->hasBinary('php')) {
            return Result::error(127, "env: can't execute 'php': No such file or directory\n");
        }
        $cwd = $workingDir !== null ? $m->path($workingDir) : $m->cwd;
        $warning = $m->isRoot() && ($m->env['COMPOSER_ALLOW_SUPERUSER'] ?? '') !== '1' ? "Do not run Composer as root/super user! See https://getcomposer.org/root for details\n" : '';

        return match ($command) {
            null, 'list' => Result::ok("   ______\n  / ____/___  __ _ ___  ___  ____ ___ _____\n / /   / __ \\/ _` / _ \\/ _ \\/ __|/ _ \\ '__/\n/ /___| (_) | (_| | |_) | (_) \\__ \\  __/ |\n\\____/ \\___/ \\__,_| .__/ \\___/|___/\\___|_|\n                 |_|\nComposer version ".self::VERSION."\n\nAvailable commands:\n  install        Installs the project dependencies from the composer.lock file if present, or falls back on the composer.json.\n  dump-autoload  Dumps the autoloader.\n  require        Adds required packages to your composer.json and installs them.\n"),
            'install', 'i', 'update', 'u' => $this->install($cwd, $options, $m, $warning, $command[0] === 'u'),
            'dump-autoload', 'dumpautoload' => $this->dumpAutoload($cwd, $options, $m, $warning),
            'require', 'req', 'create-project' => Result::error(1, $warning."\n  [Composer\\Downloader\\TransportException]\n  The \"https://repo.packagist.org/packages.json\" file could not be downloaded: le simulateur n'a pas accès à Packagist.\n"),
            'check-platform-reqs' => $this->checkPlatform($cwd, $options, $m),
            'validate' => Result::ok("./composer.json is valid\n"),
            'diagnose' => Result::ok("Checking platform settings: OK\nChecking composer version: OK\n"),
            'config' => Result::ok(),
            'clear-cache', 'clearcache', 'cc' => Result::ok("Clearing cache (cache-dir): /tmp/cache\nAll caches cleared.\n"),
            'run-script', 'run' => Result::ok(),
            default => Result::error(1, $warning."\n  Command \"{$command}\" is not defined.\n\n"),
        };
    }

    /** @return array<string,mixed>|null */
    private function readJson(Machine $m, string $path): ?array
    {
        $content = $m->fs->read($path);
        if ($content === null) {
            return null;
        }
        $data = json_decode($content, true);

        return \is_array($data) ? $data : [];
    }

    private function install(string $cwd, array $options, Machine $m, string $warning, bool $update): Result
    {
        $json = $this->readJson($m, $cwd.'/composer.json');
        if ($json === null) {
            return Result::error(1, $warning."Composer could not find a composer.json file in {$cwd}\nTo initialize a project, please create a composer.json file. See https://getcomposer.org/basic-usage\n");
        }
        if ($json === []) {
            return Result::error(1, $warning."\n  [Seld\\JsonLint\\ParsingException]\n  \"./composer.json\" does not contain valid JSON\n");
        }
        $noDev = isset($options['no-dev']);
        $out = '';
        $lock = $this->readJson($m, $cwd.'/composer.lock');
        if ($lock === null) {
            $out .= "No composer.lock file present. Updating dependencies to latest instead of installing from lock file. See https://getcomposer.org/install for more information.\nLoading composer repositories with package information\n";
            if ($this->thirdPartyRequirements($json, $noDev) !== []) {
                return Result::error(1, $warning."\n  [Composer\\Downloader\\TransportException]\n  The \"https://repo.packagist.org/packages.json\" file could not be downloaded: le simulateur n'a pas accès à Packagist.\n  Copiez aussi composer.lock dans l'image : c'est lui qui fige les versions.\n", $out, 2.0);
            }
            $lock = ['packages' => [], 'packages-dev' => []];
        } else {
            $out .= 'Installing dependencies from lock file'.($noDev ? '' : ' (including require-dev)')."\nVerifying lock file contents can be installed on current platform.\n";
        }
        // --ignore-platform-reqs (tout) ou --ignore-platform-req=ext-intl (une exigence, ou ext-* )
        $platformError = isset($options['ignore-platform-reqs']) ? '' : $this->platformProblems($json, $lock, $noDev, $m, $options['ignored-requirements'] ?? []);
        if ($platformError !== '') {
            return Result::error(2, $platformError, $out, 1.2);
        }
        $packages = [...($lock['packages'] ?? []), ...($noDev ? [] : ($lock['packages-dev'] ?? []))];
        $seconds = 1.0;
        if ($packages !== []) {
            $canUnzip = $m->facts->hasBinary('unzip') || $m->facts->hasBinary('7z') || \in_array('zip', array_map('strtolower', $m->facts->phpExtensions), true);
            $out .= sprintf("Package operations: %d installs, 0 updates, 0 removals\n", \count($packages));
            if (!$canUnzip) {
                $first = $packages[0];
                $message = sprintf("    Failed to download %s from dist: The zip extension and unzip/7z commands are both missing, skipping.\nThe php.ini used by your command-line PHP is: %s\n    Now trying to download from source\n  - Syncing %s (%s) into cache\n", $first['name'], $m->fs->exists('/usr/local/etc/php/php.ini') ? '/usr/local/etc/php/php.ini' : '(none)', $first['name'], $first['version'] ?? 'dev');
                if (!$m->facts->hasBinary('git')) {
                    $m->note('Composer a besoin de unzip (ou de l\'extension zip) pour décompresser les paquets, ou de git pour les cloner.');

                    return Result::error(1, "\nIn SyncHelper.php line 97:\n\n  Failed to download {$first['name']} from source: git was not found in your PATH, skipping source download\n\n", $out.$message, 4.0);
                }
                $out .= $message;
            }
            foreach ($packages as $package) {
                $out .= sprintf("  - Installing %s (%s): Extracting archive\n", $package['name'], $package['version'] ?? 'dev-main');
                $this->copyPackage($m, $cwd, $package['name']);
                $seconds += 0.35;
            }
        } else {
            $out .= "Nothing to install, update or remove\n";
        }
        $optimize = isset($options['optimize-autoloader']) || isset($options['o']) || isset($options['classmap-authoritative']) || isset($options['a']);
        $out .= ($optimize ? 'Generating optimized autoload files' : 'Generating autoload files')."\n";
        $this->writeAutoloader($m, $cwd, $json, $packages, $noDev, $optimize);
        $funding = \count(array_filter($packages, static fn ($p) => !empty($p['funding'])));
        if ($funding > 0) {
            $out .= sprintf("%d packages you are using are looking for funding.\nUse the `composer fund` command to find out more!\n", $funding);
        }
        if (!isset($options['no-scripts'])) {
            $scriptResult = $this->runScripts($json, $cwd, $m);
            if ($scriptResult !== null) {
                return Result::error(1, $warning.$scriptResult, $out, $seconds);
            }
        }

        return new Result(0, $out, $warning, $seconds);
    }

    private function dumpAutoload(string $cwd, array $options, Machine $m, string $warning): Result
    {
        $json = $this->readJson($m, $cwd.'/composer.json');
        if ($json === null) {
            return Result::error(1, $warning."Composer could not find a composer.json file in {$cwd}\n");
        }
        $lock = $this->readJson($m, $cwd.'/composer.lock') ?? [];
        $noDev = isset($options['no-dev']);
        $packages = [...($lock['packages'] ?? []), ...($noDev ? [] : ($lock['packages-dev'] ?? []))];
        $packages = array_values(array_filter($packages, fn ($p) => $m->fs->isDir($cwd.'/vendor/'.$p['name'])));
        $optimize = isset($options['optimize']) || isset($options['o']) || isset($options['classmap-authoritative']) || isset($options['a']);
        $this->writeAutoloader($m, $cwd, $json, $packages, $noDev, $optimize);

        return new Result(0, ($optimize ? 'Generating optimized autoload files (authoritative)' : 'Generating autoload files')."\nGenerated ".($optimize ? 'optimized ' : '')."autoload files\n", $warning, 0.8);
    }

    private function checkPlatform(string $cwd, array $options, Machine $m): Result
    {
        $json = $this->readJson($m, $cwd.'/composer.json') ?? [];
        $lock = $this->readJson($m, $cwd.'/composer.lock') ?? [];
        $problems = $this->platformProblems($json, $lock, isset($options['no-dev']), $m);

        return $problems === '' ? Result::ok("php ".($m->facts->phpVersion ?? '')."  success\n") : Result::error(2, $problems);
    }

    /** @return list<string> */
    private function thirdPartyRequirements(array $json, bool $noDev): array
    {
        $requirements = array_keys([...($json['require'] ?? []), ...($noDev ? [] : ($json['require-dev'] ?? []))]);

        return array_values(array_filter($requirements, static fn ($r) => str_contains($r, '/')));
    }

    /** @param list<string> $ignored exigences à ignorer (« ext-intl », « ext-* », « php ») */
    private function platformProblems(array $json, array $lock, bool $noDev, Machine $m, array $ignored = []): string
    {
        $problems = [];
        [$loaded] = $m->facts->loadedExtensions();
        $loaded = array_map('strtolower', $loaded);
        $requirements = [...($json['require'] ?? []), ...($noDev ? [] : ($json['require-dev'] ?? []))];
        foreach ([...($lock['platform'] ?? [])] as $name => $constraint) {
            $requirements[$name] ??= $constraint;
        }
        foreach ($requirements as $name => $constraint) {
            foreach ($ignored as $pattern) {
                if (fnmatch($pattern, (string) $name)) {
                    continue 2;
                }
            }
            if ($name === 'php' && $m->facts->phpVersion !== null && !self::satisfies($m->facts->phpVersion, (string) $constraint)) {
                $problems[] = sprintf("    - Root composer.json requires php %s but your php version (%s) does not satisfy that requirement.", $constraint, $m->facts->phpVersion);
            }
            if (str_starts_with($name, 'ext-')) {
                $extension = strtolower(substr($name, 4));
                $alias = ['zend-opcache' => 'zend opcache', 'opcache' => 'zend opcache'][$extension] ?? $extension;
                if (!\in_array($alias, $loaded, true)) {
                    $problems[] = sprintf("    - Root composer.json requires PHP extension %s * but it is missing from your system. Install or enable PHP's %s extension.", $name, $extension);
                }
            }
        }
        if ($problems === []) {
            return '';
        }

        return "Your lock file does not contain a compatible set of packages. Please run composer update.\n\n  Problem 1\n".implode("\n", $problems)."\n\nTo enable extensions, verify that they are enabled in your .ini files:\n    - /usr/local/etc/php/conf.d/docker-php-ext-sodium.ini\nYou can also run `php --ini` in a terminal to see which files are used by PHP in CLI mode.\nAlternatively, you can run Composer with `--ignore-platform-req=ext-*` to temporarily ignore these required extensions.\n";
    }

    /** Contraintes Composer courantes : ^8.2, ~8.3.0, >=8.4, 8.4.*, >=8.2 <8.5, ^8.2 || ^9.0. */
    public static function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }
        foreach (preg_split('/\s*\|\|?\s*/', $constraint) ?: [] as $alternative) {
            $ok = true;
            foreach (preg_split('/\s*,\s*|\s+/', trim($alternative)) ?: [] as $part) {
                if ($part !== '' && !self::satisfiesOne($version, $part)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    private static function satisfiesOne(string $version, string $part): bool
    {
        if (!preg_match('/^(\^|~|>=|<=|>|<|==|=|!=)?v?([\d.*]+)/', $part, $match)) {
            return true;
        }
        $op = $match[1] ?? '';
        $target = $match[2];
        if (str_contains($target, '*')) {
            return str_starts_with($version.'.', rtrim($target, '*'));
        }
        $pieces = explode('.', $target);
        $normalized = implode('.', array_pad($pieces, 3, '0'));

        return match ($op) {
            '^' => version_compare($version, $normalized, '>=') && version_compare($version, ((int) $pieces[0] + 1).'.0.0', '<'),
            '~' => version_compare($version, $normalized, '>=') && version_compare($version, \count($pieces) > 2 ? $pieces[0].'.'.((int) $pieces[1] + 1).'.0' : ((int) $pieces[0] + 1).'.0.0', '<'),
            '>=' => version_compare($version, $normalized, '>='),
            '<=' => version_compare($version, $normalized, '<='),
            '>' => version_compare($version, $normalized, '>'),
            '<' => version_compare($version, $normalized, '<'),
            '!=' => version_compare($version, $normalized, '!='),
            default => version_compare(implode('.', \array_slice(explode('.', $version), 0, \count($pieces))), $target, '=='),
        };
    }

    private function copyPackage(Machine $m, string $cwd, string $name): void
    {
        $target = $cwd.'/vendor/'.$name;
        $host = $m->hostProject !== null ? rtrim($m->hostProject, '/').'/vendor/'.$name : null;
        if ($host !== null && is_dir($host)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($host, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $m->fs->writeFromHost($target.substr($file->getPathname(), \strlen($host)), $file->getPathname());
                }
            }

            return;
        }
        $m->fs->write($target.'/composer.json', json_encode(['name' => $name], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * @param list<array<string,mixed>> $packages
     */
    private function writeAutoloader(Machine $m, string $cwd, array $json, array $packages, bool $noDev, bool $optimize): void
    {
        $psr4 = [];
        $files = [];
        $add = static function (array $autoload, string $base) use (&$psr4, &$files): void {
            foreach ($autoload['psr-4'] ?? [] as $namespace => $paths) {
                foreach ((array) $paths as $path) {
                    $psr4[$namespace][] = rtrim($base.'/'.$path, '/');
                }
            }
            foreach ($autoload['files'] ?? [] as $file) {
                $files[] = $base.'/'.$file;
            }
        };
        foreach ($packages as $package) {
            $add($package['autoload'] ?? [], 'vendor/'.$package['name']);
        }
        $add($json['autoload'] ?? [], '.');
        if (!$noDev) {
            $add($json['autoload-dev'] ?? [], '.');
        }
        $export = static fn ($value) => var_export($value, true);
        $code = "<?php\n\n// autoload.php @generated by Composer ".self::VERSION."\n\$baseDir = dirname(__DIR__);\n\$psr4 = ".$export($psr4).";\n\$files = ".$export(array_values(array_unique($files))).";\n\n";
        $code .= <<<'PHP'
            spl_autoload_register(static function (string $class) use ($baseDir, $psr4): void {
                foreach ($psr4 as $prefix => $dirs) {
                    if (!str_starts_with($class, $prefix)) {
                        continue;
                    }
                    $relative = str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                    foreach ($dirs as $dir) {
                        $file = $baseDir.'/'.ltrim($dir, './').'/'.$relative;
                        if ($dir === '.') {
                            $file = $baseDir.'/'.$relative;
                        }
                        if (is_file($file)) {
                            require $file;

                            return;
                        }
                    }
                }
            });
            foreach ($files as $file) {
                require_once $baseDir.'/'.ltrim($file, './');
            }

            return null;

            PHP;
        $m->fs->write($cwd.'/vendor/autoload.php', $code, null, $m->isRoot() ? null : $m->userName());
        $m->fs->write($cwd.'/vendor/composer/installed.json', json_encode(['packages' => $packages, 'dev' => !$noDev], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
        if ($optimize) {
            $m->fs->write($cwd.'/vendor/composer/autoload_classmap.php', "<?php\n\n// autoload_classmap.php @generated by Composer\nreturn [];\n");
        }
    }

    /** Scripts post-install : un « bin/console » ou « artisan » absent fait échouer l'installation. */
    private function runScripts(array $json, string $cwd, Machine $m): ?string
    {
        $scripts = $json['scripts'] ?? [];
        $queue = array_merge((array) ($scripts['post-install-cmd'] ?? []), (array) ($scripts['post-autoload-dump'] ?? []));
        $seen = [];
        while ($queue !== []) {
            $script = array_shift($queue);
            if (!\is_string($script) || isset($seen[$script])) {
                continue;
            }
            $seen[$script] = true;
            if (str_starts_with($script, '@') && isset($scripts[substr($script, 1)])) {
                $referenced = $scripts[substr($script, 1)];
                foreach (\is_array($referenced) ? array_keys(array_is_list($referenced) ? array_flip($referenced) : $referenced) : [$referenced] as $entry) {
                    $queue[] = (string) $entry;
                }
                continue;
            }
            if (preg_match('#(bin/console|artisan)#', $script, $binary) && !$m->fs->isFile($cwd.'/'.$binary[1])) {
                return sprintf("\nScript %s returned with error code 1\n!!  Could not open input file: %s\n!!\nScript @auto-scripts was called via post-install-cmd\n", $script, $binary[1]);
            }
        }

        return null;
    }
}
