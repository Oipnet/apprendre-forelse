<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Catalog;

/**
 * Les paquets que l'on peut installer dans les images du simulateur (apk sur Alpine, apt sur Debian),
 * avec les commandes qu'ils apportent et leur taille approximative. Un paquet inconnu échoue comme
 * sur une vraie machine : « no such package » (apk), « Unable to locate package » (apt).
 */
final class Packages
{
    /** @var array<string, array{bin?: list<string>, size: int}> taille en Mo */
    private const ALPINE = [
        'git' => ['bin' => ['git'], 'size' => 18],
        'curl' => ['bin' => ['curl'], 'size' => 1],
        'wget' => ['bin' => ['wget'], 'size' => 1],
        'unzip' => ['bin' => ['unzip'], 'size' => 1],
        'zip' => ['bin' => ['zip'], 'size' => 1],
        'bash' => ['bin' => ['bash'], 'size' => 3],
        'nano' => ['bin' => ['nano'], 'size' => 2],
        'vim' => ['bin' => ['vim'], 'size' => 30],
        'nodejs' => ['bin' => ['node'], 'size' => 45],
        'npm' => ['bin' => ['npm', 'npx'], 'size' => 10],
        'yarn' => ['bin' => ['yarn'], 'size' => 5],
        'postgresql-client' => ['bin' => ['psql', 'pg_dump'], 'size' => 6],
        'mysql-client' => ['bin' => ['mysql', 'mysqldump'], 'size' => 30],
        'mariadb-client' => ['bin' => ['mysql', 'mariadb', 'mysqldump'], 'size' => 30],
        'supervisor' => ['bin' => ['supervisord', 'supervisorctl'], 'size' => 3],
        'shadow' => ['bin' => ['useradd', 'usermod', 'groupadd'], 'size' => 3],
        'su-exec' => ['bin' => ['su-exec'], 'size' => 0],
        'tzdata' => ['size' => 3],
        'ca-certificates' => ['size' => 1],
        'openssl' => ['bin' => ['openssl'], 'size' => 1],
        'icu-dev' => ['size' => 45],
        'icu-libs' => ['size' => 30],
        'icu-data-full' => ['size' => 50],
        'libzip-dev' => ['size' => 2],
        'libzip' => ['size' => 1],
        'oniguruma-dev' => ['size' => 1],
        'postgresql-dev' => ['size' => 20],
        'libpq-dev' => ['size' => 4],
        'libpq' => ['size' => 1],
        'libpng-dev' => ['size' => 2],
        'libjpeg-turbo-dev' => ['size' => 2],
        'freetype-dev' => ['size' => 3],
        'libwebp-dev' => ['size' => 2],
        'libxml2-dev' => ['size' => 8],
        'libxslt-dev' => ['size' => 2],
        'sqlite-dev' => ['size' => 3],
        'linux-headers' => ['size' => 8],
        'gmp-dev' => ['size' => 1],
        'openssl-dev' => ['size' => 10],
        'rabbitmq-c-dev' => ['size' => 1],
        'imagemagick-dev' => ['size' => 20],
        'acl' => ['bin' => ['setfacl', 'getfacl'], 'size' => 0],
        'autoconf' => ['bin' => ['autoconf'], 'size' => 3],
        'build-base' => ['bin' => ['gcc', 'g++', 'make'], 'size' => 190],
        'gcc' => ['bin' => ['gcc'], 'size' => 100],
        'g++' => ['bin' => ['g++'], 'size' => 40],
        'make' => ['bin' => ['make'], 'size' => 1],
        'libc-dev' => ['size' => 4],
        'musl-dev' => ['size' => 4],
        'pkgconf' => ['bin' => ['pkg-config'], 'size' => 0],
        're2c' => ['bin' => ['re2c'], 'size' => 2],
        'file' => ['bin' => ['file'], 'size' => 0],
        'dpkg' => ['size' => 2],
        'dpkg-dev' => ['size' => 1],
        'gnupg' => ['bin' => ['gpg'], 'size' => 8],
        'procps' => ['bin' => ['ps', 'top'], 'size' => 1],
        'htop' => ['bin' => ['htop'], 'size' => 0],
        'less' => ['bin' => ['less'], 'size' => 0],
        'jq' => ['bin' => ['jq'], 'size' => 1],
        'rsync' => ['bin' => ['rsync'], 'size' => 1],
        'openssh-client' => ['bin' => ['ssh', 'scp'], 'size' => 5],
        'redis' => ['bin' => ['redis-cli', 'redis-server'], 'size' => 3],
        'python3' => ['bin' => ['python3'], 'size' => 50],
        'ffmpeg' => ['bin' => ['ffmpeg'], 'size' => 60],
        'imagemagick' => ['bin' => ['convert', 'magick'], 'size' => 20],
        'php84' => ['bin' => ['php84'], 'size' => 30],
        'php83' => ['bin' => ['php83'], 'size' => 30],
        'composer' => ['bin' => ['composer'], 'size' => 5],
        'nginx' => ['bin' => ['nginx'], 'size' => 2],
        'dcron' => ['bin' => ['crond'], 'size' => 0],
    ];

    /** @var array<string, array{bin?: list<string>, size: int}> */
    private const DEBIAN = [
        'git' => ['bin' => ['git'], 'size' => 100],
        'curl' => ['bin' => ['curl'], 'size' => 1],
        'wget' => ['bin' => ['wget'], 'size' => 3],
        'unzip' => ['bin' => ['unzip'], 'size' => 1],
        'zip' => ['bin' => ['zip'], 'size' => 1],
        'nano' => ['bin' => ['nano'], 'size' => 3],
        'vim' => ['bin' => ['vim'], 'size' => 40],
        'vim-tiny' => ['bin' => ['vi'], 'size' => 2],
        'less' => ['bin' => ['less'], 'size' => 1],
        'nodejs' => ['bin' => ['node'], 'size' => 60],
        'npm' => ['bin' => ['npm', 'npx'], 'size' => 30],
        'postgresql-client' => ['bin' => ['psql', 'pg_dump'], 'size' => 10],
        'default-mysql-client' => ['bin' => ['mysql', 'mysqldump'], 'size' => 40],
        'mariadb-client' => ['bin' => ['mysql', 'mariadb', 'mysqldump'], 'size' => 40],
        'supervisor' => ['bin' => ['supervisord', 'supervisorctl'], 'size' => 5],
        'cron' => ['bin' => ['cron', 'crontab'], 'size' => 1],
        'procps' => ['bin' => ['ps', 'top'], 'size' => 2],
        'htop' => ['bin' => ['htop'], 'size' => 1],
        'acl' => ['bin' => ['setfacl', 'getfacl'], 'size' => 1],
        'gosu' => ['bin' => ['gosu'], 'size' => 1],
        'tzdata' => ['size' => 3],
        'ca-certificates' => ['size' => 1],
        'gnupg' => ['bin' => ['gpg'], 'size' => 10],
        'lsb-release' => ['bin' => ['lsb_release'], 'size' => 1],
        'apt-transport-https' => ['size' => 0],
        'software-properties-common' => ['bin' => ['add-apt-repository'], 'size' => 5],
        'openssl' => ['bin' => ['openssl'], 'size' => 2],
        'libicu-dev' => ['size' => 50],
        'libzip-dev' => ['size' => 2],
        'libzip4' => ['size' => 1],
        'libonig-dev' => ['size' => 1],
        'libpq-dev' => ['size' => 4],
        'libpq5' => ['size' => 1],
        'libpng-dev' => ['size' => 2],
        'libjpeg-dev' => ['size' => 2],
        'libjpeg62-turbo-dev' => ['size' => 2],
        'libfreetype6-dev' => ['size' => 3],
        'libfreetype-dev' => ['size' => 3],
        'libwebp-dev' => ['size' => 2],
        'libxml2-dev' => ['size' => 8],
        'libxslt1-dev' => ['size' => 2],
        'libsqlite3-dev' => ['size' => 3],
        'libgmp-dev' => ['size' => 1],
        'libssl-dev' => ['size' => 10],
        'librabbitmq-dev' => ['size' => 1],
        'libmagickwand-dev' => ['size' => 30],
        'libbz2-dev' => ['size' => 1],
        'libldap2-dev' => ['size' => 2],
        'libcurl4-openssl-dev' => ['size' => 2],
        'libyaml-dev' => ['size' => 1],
        'zlib1g-dev' => ['size' => 1],
        'build-essential' => ['bin' => ['gcc', 'g++', 'make'], 'size' => 200],
        'gcc' => ['bin' => ['gcc'], 'size' => 100],
        'make' => ['bin' => ['make'], 'size' => 1],
        'pkg-config' => ['bin' => ['pkg-config'], 'size' => 1],
        'autoconf' => ['bin' => ['autoconf'], 'size' => 3],
        'jq' => ['bin' => ['jq'], 'size' => 1],
        'rsync' => ['bin' => ['rsync'], 'size' => 1],
        'openssh-client' => ['bin' => ['ssh', 'scp'], 'size' => 5],
        'python3' => ['bin' => ['python3'], 'size' => 30],
        'ffmpeg' => ['bin' => ['ffmpeg'], 'size' => 300],
        'imagemagick' => ['bin' => ['convert', 'magick'], 'size' => 60],
        'php' => ['bin' => ['php'], 'size' => 60],
        'php-cli' => ['bin' => ['php'], 'size' => 30],
        'php8.2' => ['bin' => ['php'], 'size' => 30],
        'composer' => ['bin' => ['composer'], 'size' => 10],
        'apache2' => ['bin' => ['apache2', 'apachectl'], 'size' => 10],
        'nginx' => ['bin' => ['nginx'], 'size' => 5],
        'redis-tools' => ['bin' => ['redis-cli'], 'size' => 2],
    ];

    /** Bibliothèques d'exécution tirées par un paquet de développement (retirées avec lui si personne ne les a demandées). */
    public const DEPENDENCIES = [
        'alpine' => [
            'icu-dev' => ['icu-libs'], 'libzip-dev' => ['libzip'], 'postgresql-dev' => ['libpq'], 'libpq-dev' => ['libpq'],
            'libpng-dev' => ['libpng'], 'freetype-dev' => ['freetype'], 'libjpeg-turbo-dev' => ['libjpeg-turbo'], 'libxslt-dev' => ['libxslt'],
            'gmp-dev' => ['gmp'], 'rabbitmq-c-dev' => ['rabbitmq-c'], 'imagemagick-dev' => ['imagemagick-libs'], 'libwebp-dev' => ['libwebp'],
            'oniguruma-dev' => ['oniguruma'], 'yaml-dev' => ['yaml'], 'npm' => ['nodejs'], 'build-base' => ['gcc', 'g++', 'make', 'musl-dev'],
        ],
        'debian' => [
            'libicu-dev' => ['libicu76'], 'libzip-dev' => ['libzip5'], 'libpq-dev' => ['libpq5'], 'libpng-dev' => ['libpng16-16'],
            'libfreetype-dev' => ['libfreetype6'], 'libfreetype6-dev' => ['libfreetype6'], 'libjpeg-dev' => ['libjpeg62-turbo'], 'libjpeg62-turbo-dev' => ['libjpeg62-turbo'],
            'libxslt1-dev' => ['libxslt1.1'], 'libgmp-dev' => ['libgmp10'], 'librabbitmq-dev' => ['librabbitmq4'], 'libmagickwand-dev' => ['libmagickwand-7.q16-10'],
            'libwebp-dev' => ['libwebp7'], 'libonig-dev' => ['libonig5'], 'libyaml-dev' => ['libyaml-0-2'], 'npm' => ['nodejs'], 'build-essential' => ['gcc', 'g++', 'make'],
        ],
    ];

    /** Méta-paquet des images php Alpine : ce qu'il faut pour compiler une extension (phpize). */
    public const PHPIZE_DEPS_ALPINE = ['autoconf', 'dpkg-dev', 'dpkg', 'file', 'g++', 'gcc', 'libc-dev', 'make', 'pkgconf', 're2c'];

    /** @return array{bin?: list<string>, size: int}|null */
    public static function find(string $os, string $name): ?array
    {
        $catalog = $os === 'alpine' ? self::ALPINE : self::DEBIAN;
        if (isset($catalog[$name])) {
            return $catalog[$name];
        }
        // Bibliothèques d'exécution : connues dès qu'un paquet de développement les tire.
        foreach (self::DEPENDENCIES[$os === 'alpine' ? 'alpine' : 'debian'] as $deps) {
            if (\in_array($name, $deps, true)) {
                return ['size' => 3];
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function dependencies(string $os, string $name): array
    {
        return self::DEPENDENCIES[$os === 'alpine' ? 'alpine' : 'debian'][$name] ?? [];
    }

    /** Suggestion à la « Le paquet que vous cherchez s'appelle peut-être… », pour l'indice du mentor. */
    public static function closest(string $os, string $name): ?string
    {
        $catalog = array_keys($os === 'alpine' ? self::ALPINE : self::DEBIAN);
        $best = null;
        $bestDistance = 4;
        foreach ($catalog as $candidate) {
            $distance = levenshtein($name, $candidate);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        return $best;
    }
}
