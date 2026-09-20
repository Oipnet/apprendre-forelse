<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Engine;

use Forelse\DockerSim\Catalog\BaseImage;
use Forelse\DockerSim\State\Blob;
use Forelse\DockerSim\State\Image;
use Forelse\DockerSim\State\ImageConfig;
use Forelse\DockerSim\State\Layer;

/** Fabrique l'image locale d'une image du catalogue (docker pull, FROM). */
final class ImageFactory
{
    private const APACHE_MODULES = ['access_compat', 'alias', 'auth_basic', 'authn_core', 'authn_file', 'authz_core', 'authz_host', 'authz_user', 'autoindex', 'deflate', 'dir', 'env', 'filter', 'mime', 'mpm_prefork', 'negotiation', 'php', 'reqtimeout', 'setenvif', 'status'];

    public static function fromBase(BaseImage $base): Image
    {
        $files = $base->files;
        $users = self::users($base);
        $passwd = '';
        foreach ($users as $name => $uid) {
            $passwd .= sprintf("%s:x:%d:%d:%s:%s:%s\n", $name, $uid, $uid, $name, $name === 'root' ? '/root' : ($name === 'www-data' ? '/var/www' : '/home/'.$name), $name === 'root' ? '/bin/sh' : '/sbin/nologin');
        }
        $files['/etc/passwd'] = $passwd;
        $files['/etc/group'] ??= implode('', array_map(static fn ($name, $uid) => sprintf("%s:x:%d:\n", $name, $uid), array_keys($users), $users));
        $files['/etc/os-release'] ??= $base->os === 'alpine'
            ? "NAME=\"Alpine Linux\"\nID=alpine\nVERSION_ID=3.22.1\nPRETTY_NAME=\"Alpine Linux v3.22\"\nHOME_URL=\"https://alpinelinux.org/\"\nBUG_REPORT_URL=\"https://gitlab.alpinelinux.org/alpine/aports/-/issues\"\n"
            : "PRETTY_NAME=\"Debian GNU/Linux 13 (trixie)\"\nNAME=\"Debian GNU/Linux\"\nVERSION_ID=\"13\"\nVERSION=\"13 (trixie)\"\nVERSION_CODENAME=trixie\nID=debian\nHOME_URL=\"https://www.debian.org/\"\nSUPPORT_URL=\"https://www.debian.org/support\"\nBUG_REPORT_URL=\"https://bugs.debian.org/\"\n";
        $dirs = ['/tmp', '/root', '/var/tmp', '/usr/local/bin'];
        if ($base->workdir !== null) {
            $dirs[] = $base->workdir;
        }
        if ($base->docroot !== null) {
            $dirs[] = $base->docroot;
        }
        foreach ($base->volumes as $volume) {
            $dirs[] = $volume;
        }
        if ($base->isPhp()) {
            $dirs[] = '/usr/local/etc/php/conf.d';
            $dirs[] = \Forelse\DockerSim\Shell\Facts::extensionDir($base->phpVersion);
        }
        $blobs = [];
        foreach ($files as $path => $content) {
            // Un entier : un fichier dont seule la taille compte (un binaire, une archive phar).
            $blobs[$path] = \is_int($content) ? Blob::virtual($content) : Blob::encode($content);
        }
        $meta = [];
        foreach ($base->owners as $path => $owner) {
            $dirs[] = $path;
            $meta[$path] = [0755, $owner];
        }
        foreach (array_keys($files) as $path) {
            // Les programmes livrés par l'image sont exécutables.
            if (preg_match('#^/(usr/(local/)?)?s?bin/#', (string) $path) || $path === ($base->entrypoint[0] ?? null) || $path === ($base->cmd[0] ?? null)) {
                $meta[$path] = [0755, 'root'];
            }
        }
        $fileBytes = array_sum(array_map(static fn ($b) => Blob::size($b), $blobs));
        // Le « reste » de l'image (binaires, bibliothèques) : un fichier virtuel à sa taille.
        $blobs['/usr/share/sim-base/'.str_replace('/', '_', $base->reference())] = Blob::virtual(max(0, $base->sizeBytes - $fileBytes));
        if ($base->docroot !== null && $base->kind === 'apache-php') {
            $meta[$base->docroot] = [0755, 'www-data'];
        }

        $history = self::history($base);
        $layers = [];
        $count = max(1, \count($history));
        foreach ($history as $index => [$createdBy, $share]) {
            $empty = $share === 0.0;
            $layers[] = new Layer(
                id: 'sha256:'.hash('sha256', $base->reference().'#'.$index),
                createdBy: $createdBy,
                size: $empty ? 0 : (int) round($base->sizeBytes * $share),
                cacheKey: hash('sha256', $base->digest().'#'.$index),
                files: $index === 0 ? $blobs : [],
                createdAt: $base->createdAt,
                empty: $empty,
                meta: $index === 0 ? $meta : [],
                dirs: $index === 0 ? $dirs : [],
            );
        }
        // La taille totale reste celle du catalogue, quelle que soit la répartition.
        $layers[0] = new Layer($layers[0]->id, $layers[0]->createdBy, $layers[0]->size + $base->sizeBytes - array_sum(array_map(static fn (Layer $l) => $l->size, $layers)), $layers[0]->cacheKey, $layers[0]->files, [], $layers[0]->createdAt, false, $layers[0]->meta, $layers[0]->dirs);

        $config = new ImageConfig(
            healthcheck: $base->healthcheck,
            env: $base->env,
            cmd: $base->cmd === [] ? null : $base->cmd,
            entrypoint: $base->entrypoint,
            workdir: $base->workdir ?? '/',
            user: $base->user,
            exposed: array_map(static fn (int $p) => $p.'/tcp', $base->exposed),
            volumes: $base->volumes,
            stopSignal: $base->kind === 'apache-php' ? 'SIGWINCH' : ($base->kind === 'php-fpm' ? 'SIGQUIT' : ($base->kind === 'nginx' ? 'SIGQUIT' : null)),
        );

        return new Image(
            id: 'sha256:'.hash('sha256', 'image:'.$base->reference()),
            tags: [$base->reference()],
            layers: $layers,
            config: $config,
            base: $base->reference(),
            kind: $base->kind,
            os: $base->os,
            packages: self::packages($base),
            phpExtensions: $base->phpExtensions,
            binaries: $base->binaries,
            apacheModules: $base->kind === 'apache-php' ? self::APACHE_MODULES : [],
            phpVersion: $base->phpVersion,
            docroot: $base->docroot,
            createdAt: $base->createdAt,
            pulled: true,
            users: $users,
        );
    }

    /** @return array<string,int> */
    private static function users(BaseImage $base): array
    {
        $alpine = $base->os === 'alpine';
        $users = ['root' => 0, 'nobody' => 65534];
        if ($base->isPhp() || \in_array($base->kind, ['nginx', 'static-web'], true)) {
            $users['www-data'] = $alpine ? 82 : 33;
        }

        return $users + match ($base->kind) {
            'nginx' => ['nginx' => 101],
            'postgres' => ['postgres' => $alpine ? 70 : 999],
            'mysql', 'mariadb' => ['mysql' => 999],
            'redis' => ['redis' => 999],
            'node' => ['node' => 1000],
            'mailpit' => ['mailhog' => 1000],
            default => [],
        } + ($base->user !== null && !\in_array($base->user, ['root'], true) ? [$base->user => 1000] : []);
    }

    /** @return list<string> */
    private static function packages(BaseImage $base): array
    {
        $packages = $base->os === 'alpine' ? ['ca-certificates', 'openssl', 'tar', 'xz'] : ['ca-certificates', 'curl', 'xz-utils', 'perl'];
        if ($base->isPhp() && $base->os !== 'alpine') {
            // Les images php Debian gardent les outils de compilation ($PHPIZE_DEPS).
            array_push($packages, 'autoconf', 'dpkg-dev', 'file', 'g++', 'gcc', 'libc-dev', 'make', 'pkg-config', 're2c');
        }
        if ($base->isPhp()) {
            // Bibliothèques des extensions compilées d'origine.
            array_push($packages, $base->os === 'alpine' ? 'oniguruma' : 'libonig5', $base->os === 'alpine' ? 'libcurl' : 'libcurl4', $base->os === 'alpine' ? 'sqlite-libs' : 'libsqlite3-0');
        }
        if ($base->kind === 'composer') {
            array_push($packages, 'git', 'unzip', 'zip', 'bash', 'openssh-client', 'libzip');
        }

        return $packages;
    }

    /** @return list<array{0: string, 1: float}> ligne de l'historique, part de la taille (0 = couche vide) */
    private static function history(BaseImage $base): array
    {
        $os = $base->os === 'alpine' ? "ADD alpine-minirootfs-3.22.1-x86_64.tar.gz / # buildkit" : "# debian.sh --arch 'amd64' out/ 'trixie' '@1757289600'";
        $lines = [[$os, 0.15], ['CMD ["'.($base->os === 'alpine' ? '/bin/sh' : 'bash').'"]', 0.0]];
        foreach ($base->env as $key => $value) {
            if ($key !== 'PATH') {
                $lines[] = ['ENV '.$key.'='.(\strlen($value) > 40 ? substr($value, 0, 37).'...' : $value), 0.0];
            }
        }
        $runs = max(1, $base->layers - 1);
        $share = 0.85 / $runs;
        $descriptions = match (true) {
            $base->isPhp() => ['RUN /bin/sh -c set -eux; apt-get update; apt-get install -y --no-install-recommends $PHPIZE_DEPS ca-certificates curl xz-utils; rm -rf /var/lib/apt/lists/* # buildkit', 'RUN /bin/sh -c set -eux; mkdir -p "$PHP_INI_DIR/conf.d"; # buildkit', 'COPY docker-php-source /usr/local/bin/ # buildkit', 'RUN /bin/sh -c set -eux; ./configure --with-config-file-path="$PHP_INI_DIR" ...; make -j "$(nproc)"; make install # buildkit', 'COPY docker-php-ext-* docker-php-entrypoint /usr/local/bin/ # buildkit', 'RUN /bin/sh -c docker-php-ext-enable sodium # buildkit'],
            default => ['RUN /bin/sh -c set -eux; # installation des paquets de l\'image # buildkit', 'COPY docker-entrypoint.sh /usr/local/bin/ # buildkit'],
        };
        for ($i = 0; $i < $runs; ++$i) {
            $lines[] = [$descriptions[$i % \count($descriptions)], $share];
        }
        if ($base->entrypoint !== null) {
            $lines[] = ['ENTRYPOINT '.json_encode($base->entrypoint, \JSON_UNESCAPED_SLASHES), 0.0];
        }
        if ($base->workdir !== null) {
            $lines[] = ['WORKDIR '.$base->workdir, 0.0];
        }
        foreach ($base->exposed as $port) {
            $lines[] = ['EXPOSE map['.$port.'/tcp:{}]', 0.0];
        }
        if ($base->cmd !== []) {
            $lines[] = ['CMD '.json_encode($base->cmd, \JSON_UNESCAPED_SLASHES), 0.0];
        }

        return $lines;
    }
}
