<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell\Command;

use Forelse\DockerSim\Catalog\PhpExtensions;
use Forelse\DockerSim\Fs\MemoryFs;
use Forelse\DockerSim\Shell\Interpreter;
use Forelse\DockerSim\Runtime\PhpExit;
use Forelse\DockerSim\Shell\Facts;
use Forelse\DockerSim\Shell\Machine;
use Forelse\DockerSim\Shell\Result;
use Forelse\DockerSim\State\Blob;

/**
 * Les outils des images php officielles : docker-php-ext-install / -enable / -configure, pecl,
 * install-php-extensions (mlocati), et php lui-même (-v, -m, -i, -r, scripts).
 */
final class PhpCommands implements Command
{
    private const CONF_D = '/usr/local/etc/php/conf.d';

    public function names(): array
    {
        return ['docker-php-ext-install', 'docker-php-ext-enable', 'docker-php-ext-configure', 'docker-php-source', 'pecl', 'pear', 'phpize', 'install-php-extensions', 'php', 'php-fpm', 'php-config'];
    }

    public function run(string $name, array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        return match ($name) {
            'docker-php-ext-install' => $this->extInstall($args, $m),
            'docker-php-ext-enable' => $this->extEnable($args, $m),
            'docker-php-ext-configure' => $this->extConfigure($args, $m),
            'docker-php-source' => Result::ok('', 0.5),
            'pecl' => $this->pecl($args, $m),
            'pear' => Result::ok('', 0.3),
            'phpize' => $this->phpize($m),
            'install-php-extensions' => $this->installPhpExtensions($args, $m),
            'php' => $this->php($args, $m, $stdin, $sh),
            'php-fpm' => $this->phpFpm($args, $m),
            'php-config' => Result::ok(Facts::extensionDir($m->facts->phpVersion)."\n"),
            default => Result::ok(),
        };
    }

    private function requireRoot(Machine $m, string $command): ?Result
    {
        return $m->isRoot() ? null : Result::error(1, "{$command}: cannot write to /usr/local/etc/php/conf.d: Permission denied\n");
    }

    private function extInstall(array $args, Machine $m): Result
    {
        if ($error = $this->requireRoot($m, 'docker-php-ext-install')) {
            return $error;
        }
        $extensions = array_values(array_filter($args, static fn ($a) => $a[0] !== '-'));
        if ($extensions === []) {
            return Result::error(1, "usage: docker-php-ext-install [-jN] [--ini-name NAME] ext-name [ext-name ...]\n   ie: docker-php-ext-install gd mysqli\n       docker-php-ext-install pdo pdo_mysql\n       docker-php-ext-install -j5 gd mbstring mysqli pdo pdo_mysql shmop\n\nif custom ./configure arguments are necessary, see docker-php-ext-configure\n\nPossible values for ext-name:\nbcmath bz2 calendar ctype curl dba dl_test dom enchant exif ffi fileinfo filter ftp gd gettext gmp hash iconv imap intl json ldap mbstring mysqli oci8 odbc opcache pcntl pdo pdo_dblib pdo_firebird pdo_mysql pdo_oci pdo_odbc pdo_pgsql pdo_sqlite pgsql phar posix pspell random readline reflection session shmop simplexml snmp soap sockets sodium spl standard sysvmsg sysvsem sysvshm tidy tokenizer xml xmlreader xmlwriter xsl zend_test zip\n\nSome of the above modules are already compiled into PHP; please check\nthe output of \"php -i\" to see which modules are already loaded.\n");
        }
        $parallel = (bool) array_filter($args, static fn ($a) => preg_match('/^-j\d+$/', $a));
        $out = '';
        $seconds = 0.0;
        foreach ($extensions as $extension) {
            $info = PhpExtensions::core($extension);
            if ($info === null) {
                if (PhpExtensions::pecl($extension) !== null) {
                    return Result::error(1, "error: /usr/src/php/ext/{$extension} does not exist\n\nusage: docker-php-ext-install [-jN] [--ini-name NAME] ext-name [ext-name ...]\n", $out, $seconds);
                }

                return Result::error(1, "error: /usr/src/php/ext/{$extension} does not exist\n", $out, $seconds);
            }
            $missing = PhpExtensions::missing($info[$m->facts->os === 'alpine' ? 'alpine' : 'debian'], $m->facts->packageNames());
            $out .= sprintf("Configuring for:\nPHP Api Version:         ".Facts::phpApi($m->facts->phpVersion)."\nZend Module Api No:      ".Facts::phpApi($m->facts->phpVersion)."\nZend Extension Api No:   4".Facts::phpApi($m->facts->phpVersion)."\nchecking for grep that handles long lines and -e... /bin/grep\nchecking for a sed that does not truncate output... /bin/sed\nchecking for %s support... yes, shared\n", $extension);
            if ($missing !== []) {
                $m->note(sprintf('L\'extension %s a besoin du paquet %s pour compiler.', $extension, implode(', ', $missing)));

                return Result::error(1, ($info['error'] ?? 'configure: error: missing dependency')."\n", $out, $seconds + 6.0);
            }
            $out .= sprintf("/bin/bash /usr/src/php/ext/%s/libtool --tag=CC --mode=compile cc -I. -I/usr/src/php/ext/%s -I/usr/local/include/php/main -fstack-protector-strong -fpic -fpie -O2 ...\nBuild complete.\nDon't forget to run 'make test'.\n\n+ strip --strip-all modules/%s.so\nInstalling shared extensions:     %s/\nfind . -name \\*.gcno -o -name \\*.gcda | xargs rm -f\n", $extension, $extension, $extension, Facts::extensionDir($m->facts->phpVersion));
            $this->addExtension($m, $extension, $extension === 'intl' ? 3_200_000 : 900_000);
            $seconds += match ($extension) { 'intl' => 38.0, 'gd' => 25.0, 'soap', 'xsl' => 18.0, default => 9.0 };
        }
        $out .= "find . -name \\*.gcno -o -name \\*.gcda | xargs rm -f\nfind . -name \\*.lo -o -name \\*.o -o -name \\*.dep | xargs rm -f\n";
        if ($parallel) {
            $seconds *= 0.45;
        }

        return Result::ok($out, $seconds);
    }

    private function addExtension(Machine $m, string $extension, int $size): void
    {
        $extension = strtolower($extension);
        $zend = \in_array($extension, ['opcache', 'xdebug'], true);
        if ($m->fs instanceof MemoryFs) {
            $m->fs->putBlob(Facts::extensionDir($m->facts->phpVersion).'/'.$extension.'.so', Blob::virtual($size));
        } else {
            $m->fs->write(Facts::extensionDir($m->facts->phpVersion).'/'.$extension.'.so', '');
        }
        $m->fs->write(self::CONF_D.'/docker-php-ext-'.$extension.'.ini', ($zend ? 'zend_extension=' : 'extension=').$extension."\n");
        if (!\in_array($extension, array_map('strtolower', $m->facts->phpExtensions), true)) {
            $m->facts->phpExtensions[] = $extension;
        }
        $m->facts->peclBuilt = array_values(array_diff($m->facts->peclBuilt, [$extension]));
    }

    private function extEnable(array $args, Machine $m): Result
    {
        if ($error = $this->requireRoot($m, 'docker-php-ext-enable')) {
            return $error;
        }
        foreach (array_filter($args, static fn ($a) => $a[0] !== '-') as $extension) {
            $extension = strtolower($extension);
            if (!\in_array($extension, $m->facts->peclBuilt, true) && !\in_array($extension, array_map('strtolower', $m->facts->phpExtensions), true) && !\in_array($extension, ['opcache'], true)) {
                return Result::error(1, "error: '{$extension}' does not exist\n\nusage: docker-php-ext-enable [options] module-name [module-name ...]\n");
            }
            $m->fs->write(self::CONF_D.'/docker-php-ext-'.$extension.'.ini', (\in_array($extension, ['opcache', 'xdebug'], true) ? 'zend_extension=' : 'extension=').$extension."\n");
            if (!\in_array($extension, array_map('strtolower', $m->facts->phpExtensions), true)) {
                $m->facts->phpExtensions[] = $extension;
            }
            $m->facts->peclBuilt = array_values(array_diff($m->facts->peclBuilt, [$extension]));
        }

        return Result::ok('', 0.3);
    }

    private function extConfigure(array $args, Machine $m): Result
    {
        $extension = $args[0] ?? '';
        if (PhpExtensions::core($extension) === null) {
            return Result::error(1, "error: /usr/src/php/ext/{$extension} does not exist\n");
        }
        if (strtolower($extension) === 'gd') {
            foreach (['--with-freetype' => ['alpine' => 'freetype-dev', 'debian' => 'libfreetype-dev|libfreetype6-dev'], '--with-jpeg' => ['alpine' => 'libjpeg-turbo-dev', 'debian' => 'libjpeg62-turbo-dev|libjpeg-dev'], '--with-webp' => ['alpine' => 'libwebp-dev', 'debian' => 'libwebp-dev']] as $flag => $packages) {
                if (\in_array($flag, $args, true) && PhpExtensions::missing([$packages[$m->facts->os === 'alpine' ? 'alpine' : 'debian']], $m->facts->packageNames()) !== []) {
                    return Result::error(1, sprintf("configure: error: Package requirements (%s) were not met\n", ltrim($flag, '-with')), '', 4.0);
                }
            }
        }

        return Result::ok("Configuring for:\nPHP Api Version:         ".Facts::phpApi($m->facts->phpVersion)."\n", 3.5);
    }

    private function phpize(Machine $m): Result
    {
        foreach (['autoconf'] as $binary) {
            if (!$m->facts->hasBinary($binary)) {
                return Result::error(1, "Cannot find autoconf. Please check your autoconf installation and the\n\$PHP_AUTOCONF environment variable. Then, rerun this script.\n", "Configuring for:\nPHP Api Version:         ".Facts::phpApi($m->facts->phpVersion)."\n");
            }
        }

        return Result::ok("Configuring for:\nPHP Api Version:         ".Facts::phpApi($m->facts->phpVersion)."\n", 1.0);
    }

    private function pecl(array $args, Machine $m): Result
    {
        $subcommand = array_shift($args) ?? '';
        if ($subcommand === 'clear-cache' || $subcommand === 'channel-update') {
            return Result::ok('', 0.4);
        }
        if ($subcommand !== 'install') {
            return Result::ok("Commands:\ninstall  Install Package\n");
        }
        if ($error = $this->requireRoot($m, 'pecl')) {
            return $error;
        }
        $out = '';
        $seconds = 0.0;
        foreach (array_filter($args, static fn ($a) => $a[0] !== '-') as $spec) {
            $name = strtolower(preg_replace('/-[\d.]+.*$/', '', $spec) ?? $spec);
            $info = PhpExtensions::pecl($name);
            if ($info === null) {
                return Result::error(1, "No releases available for package \"pecl.php.net/{$name}\"\ninstall failed\n", $out, $seconds + 1.0);
            }
            $version = preg_match('/-([\d.]+.*)$/', $spec, $v) ? $v[1] : $info['version'];
            $out .= sprintf("downloading %s-%s.tgz ...\nStarting to download %s-%s.tgz (280,321 bytes)\n....done: 280,321 bytes\n", $name, $version, $name, $version);
            // Sur Alpine, les outils de compilation ($PHPIZE_DEPS) ne sont pas installés d'office.
            if (!$m->facts->hasBinary('autoconf') || !$m->facts->hasBinary('gcc')) {
                $out .= "running: phpize\nConfiguring for:\nPHP Api Version:         ".Facts::phpApi($m->facts->phpVersion)."\nCannot find autoconf. Please check your autoconf installation and the\n\$PHP_AUTOCONF environment variable. Then, rerun this script.\n\n";
                $m->note('pecl compile les extensions : sur Alpine, installez d\'abord $PHPIZE_DEPS (apk add --no-cache $PHPIZE_DEPS).');

                return Result::error(1, "ERROR: `phpize' failed\n", $out, $seconds + 2.0);
            }
            $missing = PhpExtensions::missing($info[$m->facts->os === 'alpine' ? 'alpine' : 'debian'], $m->facts->packageNames());
            if ($missing !== []) {
                return Result::error(1, ($info['error'] ?? 'configure: error')."\nERROR: `/tmp/pear/temp/{$name}/configure --with-php-config=/usr/local/bin/php-config' failed\n", $out."running: phpize\n", $seconds + 8.0);
            }
            $out .= sprintf("running: phpize\nConfiguring for:\nPHP Api Version:         ".Facts::phpApi($m->facts->phpVersion)."\nbuilding in /tmp/pear/temp/pear-build-defaultuserXXXX/%s-%s\nrunning: /tmp/pear/temp/%s/configure --with-php-config=/usr/local/bin/php-config\n...\nBuild complete.\nDon't forget to run 'make test'.\n\nrunning: make INSTALL_ROOT=\"/tmp/pear/temp/pear-build-defaultuserXXXX/install-%s-%s\" install\nInstalling shared extensions:     /tmp/pear/temp/pear-build-defaultuserXXXX/install-%s-%s%s/\nBuild process completed successfully\nInstalling '%s/%s.so'\ninstall ok: channel://pecl.php.net/%s-%s\nconfiguration option \"php_ini\" is not set to php.ini location\nYou should add \"extension=%s.so\" to php.ini\n", $name, $version, $name, $name, $version, $name, $version, Facts::extensionDir($m->facts->phpVersion), Facts::extensionDir($m->facts->phpVersion), $name, $name, $version, $name);
            if ($m->fs instanceof MemoryFs) {
                $m->fs->putBlob(Facts::extensionDir($m->facts->phpVersion).'/'.$name.'.so', Blob::virtual(1_500_000));
                $m->fs->putBlob('/tmp/pear/cache/'.$name.'-'.$version.'.tgz', Blob::virtual(280_321));
            }
            // Compilée, mais pas activée : il faudra docker-php-ext-enable.
            if (!\in_array($name, $m->facts->peclBuilt, true)) {
                $m->facts->peclBuilt[] = $name;
            }
            $seconds += $name === 'xdebug' ? 32.0 : 24.0;
        }

        return Result::ok($out, $seconds);
    }

    private function installPhpExtensions(array $args, Machine $m): Result
    {
        if ($error = $this->requireRoot($m, 'install-php-extensions')) {
            return $error;
        }
        $out = '';
        $seconds = 2.0;
        foreach (array_filter($args, static fn ($a) => $a[0] !== '-') as $spec) {
            $name = strtolower(preg_replace('/[-@].*$/', '', $spec) ?? $spec);
            if ($name === 'composer' || $name === '@composer') {
                $m->facts->binaries[] = 'composer';
                $m->fs->write('/usr/local/bin/composer', "#!/usr/bin/env php\n", 0755);
                $out .= "### INSTALLING COMPOSER ###\n";
                continue;
            }
            if (PhpExtensions::core($name) === null && PhpExtensions::pecl($name) === null) {
                return Result::error(1, "### ERROR: Unable to find the extension {$name}\n", $out, $seconds);
            }
            $out .= sprintf("### INSTALLING REMOTE MODULE %s ###\n### INSTALLING PACKAGES AND LIBRARIES ###\n### REMOVING UNNEEDED PACKAGES ###\n", $name);
            // install-php-extensions installe les bibliothèques d'exécution et retire ce qui a servi à compiler.
            $runtime = PhpExtensions::RUNTIME[$name][$m->facts->os === 'alpine' ? 'alpine' : 'debian'] ?? '';
            if ($runtime !== '') {
                $m->facts->packages[$runtime] = ['explicit' => true, 'virtual' => null];
            }
            $this->addExtension($m, $name, 1_200_000);
            $seconds += 14.0;
        }
        $m->facts->binaries = array_values(array_unique($m->facts->binaries));

        return Result::ok($out, $seconds);
    }

    private function php(array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        $version = $m->facts->phpVersion ?? '8.4.11';
        [$loaded, $warnings] = $m->facts->loadedExtensions();
        // PHP en ligne de commande journalise l'avertissement (« PHP Warning: ») puis l'affiche (« Warning: »).
        $startup = $warnings === [] ? '' : implode('', array_map(static fn ($w) => $w."\n".preg_replace('/^PHP Warning:  /', 'Warning: ', $w)."\n", $warnings));
        // curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
        if (str_contains($stdin, 'getcomposer.org') && ($args === [] || $args[0] === '--')) {
            $dir = $m->cwd;
            $file = 'composer.phar';
            foreach ($args as $arg) {
                if (str_starts_with($arg, '--install-dir=')) {
                    $dir = $m->path(substr($arg, 14));
                } elseif (str_starts_with($arg, '--filename=')) {
                    $file = substr($arg, 11);
                }
            }
            $m->fs->write(rtrim($dir, '/').'/'.$file, "#!/usr/bin/env php\n", 0755);
            if ($file === 'composer' || \in_array($dir, ['/usr/local/bin', '/usr/bin'], true)) {
                $m->facts->binaries[] = $file;
            }

            return Result::ok($startup."All settings correct for using Composer\nDownloading...\n\nComposer (version 2.8.10) successfully installed to: ".rtrim($dir, '/').'/'.$file."\nUse it: php ".$file."\n", 2.5);
        }
        if ($args === [] ) {
            return $m->mode === Machine::EXEC && $stdin === '' ? Result::ok() : Result::ok($startup);
        }
        $first = $args[0];
        if ($first === '-v' || $first === '--version') {
            return Result::ok($startup.sprintf("PHP %s (cli) (built: Aug 14 2025 20:11:08) (NTS)\nCopyright (c) The PHP Group\nBuilt by https://github.com/docker-library/php\nZend Engine v4.4.11, Copyright (c) Zend Technologies\n%s", $version, \in_array('Zend OPcache', $loaded, true) ? "    with Zend OPcache v{$version}, Copyright (c), by Zend Technologies\n" : ''));
        }
        if ($first === '-m') {
            $php = array_values(array_filter($loaded, static fn ($e) => !\in_array($e, ['Zend OPcache', 'Xdebug'], true)));
            natcasesort($php);
            $zend = array_values(array_filter($loaded, static fn ($e) => \in_array($e, ['Zend OPcache', 'Xdebug'], true)));

            return Result::ok($startup."[PHP Modules]\n".implode("\n", $php)."\n".($zend === [] ? '' : implode("\n", $zend)."\n")."\n[Zend Modules]\n".($zend === [] ? '' : implode("\n", $zend)."\n")."\n");
        }
        if ($first === '-i') {
            $ini = $m->fs->exists('/usr/local/etc/php/php.ini') ? '/usr/local/etc/php/php.ini' : '(none)';
            $iniContent = (string) $m->fs->read('/usr/local/etc/php/php.ini');
            $display = preg_match('/^\s*display_errors\s*=\s*(\w+)/mi', $iniContent, $d) ? (strtolower($d[1]) === 'off' ? 'Off' : 'On') : 'STDOUT';
            $memory = preg_match('/^\s*memory_limit\s*=\s*(\S+)/mi', $iniContent, $mem) ? $mem[1] : '128M';
            foreach ($m->fs->files('/usr/local/etc/php/conf.d') as $file) {
                $content = (string) $m->fs->read($file);
                if (preg_match('/^\s*memory_limit\s*=\s*(\S+)/mi', $content, $mem)) {
                    $memory = $mem[1];
                }
                if (preg_match('/^\s*display_errors\s*=\s*(\w+)/mi', $content, $d)) {
                    $display = strtolower($d[1]) === 'off' || $d[1] === '0' ? 'Off' : 'On';
                }
            }

            return Result::ok($startup."phpinfo()\nPHP Version => {$version}\n\nConfiguration File (php.ini) Path => /usr/local/etc/php\nLoaded Configuration File => {$ini}\nScan this dir for additional .ini files => /usr/local/etc/php/conf.d\n\ndisplay_errors => {$display} => {$display}\nmemory_limit => {$memory} => {$memory}\n");
        }
        if ($first === '--ini') {
            $files = implode(",\n", $m->fs->files('/usr/local/etc/php/conf.d'));

            return Result::ok("Configuration File (php.ini) Path: /usr/local/etc/php\nLoaded Configuration File:         ".($m->fs->exists('/usr/local/etc/php/php.ini') ? '/usr/local/etc/php/php.ini' : '(none)')."\nScan for additional .ini files in: /usr/local/etc/php/conf.d\nAdditional .ini files parsed:      {$files}\n");
        }
        if ($first === '-r') {
            $simulated = $this->phpEval($args[1] ?? '', $m, $startup, false);
            if ($simulated !== null) {
                return $simulated;
            }
            // Dans un conteneur qui tourne, le reste est exécuté pour de vrai (exit() compris).
            if ($m->mode === Machine::EXEC && \is_callable($m->php)) {
                $script = '/tmp/.php-r-'.substr(md5($args[1] ?? ''), 0, 12).'.php';
                $m->fs->write($script, PhpExit::rewrite('<?php '.($args[1] ?? ''), $m->facts->phpVersion, $loaded));
                try {
                    [$code, $out] = ($m->php)([$script, ...\array_slice($args, 2)], $m->cwd, $m->env, $stdin);
                } finally {
                    $m->fs->delete($script);
                }

                return new Result($code, $startup.$out, '', 0.2);
            }

            return $this->phpEval($args[1] ?? '', $m, $startup);
        }
        if ($first === '-S') {
            return Result::ok($startup, 0.1);
        }
        $script = $first;
        if ($script === '-d') {
            $args = \array_slice($args, 2);
            $script = $args[0] ?? '';
        }
        $path = $m->path($script);
        if (!$m->fs->isFile($path)) {
            return Result::error(1, $startup."Could not open input file: {$script}\n");
        }
        if ($m->mode === Machine::EXEC && \is_callable($m->php)) {
            [$code, $out] = ($m->php)([$path, ...\array_slice($args, 1)], $m->cwd, $m->env, $stdin);

            return new Result($code, $startup.$out, '', 0.4);
        }
        if (basename($script) === 'composer-setup.php') {
            $m->fs->write($m->path('composer.phar'), "#!/usr/bin/env php\n", 0755);

            return Result::ok($startup."All settings correct for using Composer\nDownloading...\n\nComposer (version 2.8.10) successfully installed to: {$m->cwd}/composer.phar\nUse it: php composer.phar\n", 2.5);
        }
        if (\in_array(basename($script), ['composer.phar', 'composer'], true)) {
            return $sh->invoke(['composer', ...\array_slice($args, 1)], $m, $stdin);
        }
        $m->note(sprintf('php %s : le script n\'est pas exécuté pendant un build (considéré comme réussi).', $script));

        return Result::ok($startup, 1.5);
    }

    private function phpEval(string $code, Machine $m, string $startup, bool $fallback = true): ?Result
    {
        // Le classique de l'installation de Composer : copy(), hash_file(), unlink().
        if (preg_match("/copy\\(\\s*['\"]([^'\"]+)['\"]\\s*,\\s*['\"]([^'\"]+)['\"]\\s*\\)/", $code, $copy)) {
            if (str_starts_with($copy[1], 'http')) {
                $m->fs->write($m->path($copy[2]), "<?php // installateur téléchargé depuis {$copy[1]}\n");
            }

            return Result::ok($startup, 0.6);
        }
        if (preg_match("/unlink\\(\\s*['\"]([^'\"]+)['\"]\\s*\\)/", $code, $unlink)) {
            $m->fs->delete($m->path($unlink[1]));

            return Result::ok($startup);
        }
        if (str_contains($code, 'hash_file')) {
            return Result::ok($startup."Installer verified\n");
        }
        if (preg_match('/^\s*echo\s+(PHP_VERSION|PHP_OS|PHP_MAJOR_VERSION)\s*;?\s*$/', $code, $echo)) {
            $version = $m->facts->phpVersion ?? '8.4.11';

            return Result::ok($startup.match ($echo[1]) { 'PHP_VERSION' => $version, 'PHP_OS' => 'Linux', default => explode('.', $version)[0] });
        }
        if (preg_match("/^\s*echo\s+(?:extension_loaded|phpversion)\\(\\s*['\"](\\w+)['\"]\\s*\\)/", $code, $ext)) {
            [$loaded] = $m->facts->loadedExtensions();

            return Result::ok($startup.(\in_array(strtolower($ext[1]), array_map('strtolower', $loaded), true) ? '1' : ''));
        }
        if (preg_match("/^\s*echo\s+['\"]([^'\"]*)['\"]\\s*;?\s*$/", $code, $literal)) {
            return Result::ok($startup.stripcslashes($literal[1]));
        }
        if (!$fallback) {
            return null;
        }
        $m->note('php -r : seules quelques expressions simples sont simulées pendant un build.');

        return Result::ok($startup);
    }

    private function phpFpm(array $args, Machine $m): Result
    {
        if (\in_array('-t', $args, true) || \in_array('--test', $args, true)) {
            return Result::ok(sprintf("[%s] NOTICE: configuration file /usr/local/etc/php-fpm.conf test is successful\n\n", gmdate('d-M-Y H:i:s')));
        }
        if (\in_array('-v', $args, true)) {
            return Result::ok(sprintf("PHP %s (fpm-fcgi) (built: Aug 14 2025 20:11:08)\n", $m->facts->phpVersion ?? '8.4.11'));
        }

        return Result::ok();
    }
}
