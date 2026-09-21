<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Catalog;

/**
 * Les extensions PHP que docker-php-ext-install et pecl savent compiler, avec les bibliothèques
 * de développement dont elles ont besoin sur chaque système. Sans elles, la compilation échoue
 * avec le message du vrai « configure ».
 */
final class PhpExtensions
{
    /** Extensions compilées dans les images php officielles. */
    public const BUILTIN = [
        'Core', 'ctype', 'curl', 'date', 'dom', 'fileinfo', 'filter', 'hash', 'iconv', 'json', 'libxml',
        'mbstring', 'mysqlnd', 'openssl', 'pcre', 'PDO', 'pdo_sqlite', 'Phar', 'posix', 'random', 'readline',
        'Reflection', 'session', 'SimpleXML', 'sodium', 'SPL', 'sqlite3', 'standard', 'tokenizer', 'xml',
        'xmlreader', 'xmlwriter', 'zlib',
    ];

    /**
     * @var array<string, array{debian: list<string>, alpine: list<string>, error?: string}>
     *      paquets requis ; « error » = message de configure quand ils manquent
     */
    private const CORE = [
        'pdo_mysql' => ['debian' => [], 'alpine' => []],
        'mysqli' => ['debian' => [], 'alpine' => []],
        'pdo_pgsql' => ['debian' => ['libpq-dev'], 'alpine' => ['postgresql-dev|libpq-dev'], 'error' => 'configure: error: Cannot find libpq-fe.h. Please specify correct PostgreSQL installation path'],
        'pgsql' => ['debian' => ['libpq-dev'], 'alpine' => ['postgresql-dev|libpq-dev'], 'error' => 'configure: error: Cannot find libpq-fe.h. Please specify correct PostgreSQL installation path'],
        'intl' => ['debian' => ['libicu-dev'], 'alpine' => ['icu-dev'], 'error' => 'configure: error: Package requirements (icu-uc >= 50.1 icu-io icu-i18n) were not met'],
        'zip' => ['debian' => ['libzip-dev'], 'alpine' => ['libzip-dev'], 'error' => 'configure: error: Package requirements (libzip >= 0.11) were not met'],
        'gd' => ['debian' => ['libpng-dev'], 'alpine' => ['libpng-dev'], 'error' => 'configure: error: Package requirements (libpng) were not met'],
        'opcache' => ['debian' => [], 'alpine' => []],
        'bcmath' => ['debian' => [], 'alpine' => []],
        'pcntl' => ['debian' => [], 'alpine' => []],
        'sockets' => ['debian' => [], 'alpine' => []],
        'exif' => ['debian' => [], 'alpine' => []],
        'calendar' => ['debian' => [], 'alpine' => []],
        'ftp' => ['debian' => [], 'alpine' => []],
        'gettext' => ['debian' => [], 'alpine' => []],
        'shmop' => ['debian' => [], 'alpine' => []],
        'sysvsem' => ['debian' => [], 'alpine' => []],
        'sysvshm' => ['debian' => [], 'alpine' => []],
        'sysvmsg' => ['debian' => [], 'alpine' => []],
        'soap' => ['debian' => ['libxml2-dev'], 'alpine' => ['libxml2-dev'], 'error' => 'configure: error: Package requirements (libxml-2.0 >= 2.9.0) were not met'],
        'xsl' => ['debian' => ['libxslt1-dev'], 'alpine' => ['libxslt-dev'], 'error' => 'configure: error: Package requirements (libxslt >= 1.1.0) were not met'],
        'gmp' => ['debian' => ['libgmp-dev'], 'alpine' => ['gmp-dev'], 'error' => 'configure: error: GNU MP Library version 4.2 or greater required.'],
        'bz2' => ['debian' => ['libbz2-dev'], 'alpine' => ['bzip2-dev'], 'error' => 'configure: error: Please reinstall the BZip2 distribution'],
        'ldap' => ['debian' => ['libldap2-dev'], 'alpine' => ['openldap-dev'], 'error' => 'configure: error: Cannot find ldap.h'],
        'mbstring' => ['debian' => [], 'alpine' => ['oniguruma-dev'], 'error' => 'configure: error: Package requirements (oniguruma) were not met'],
        'pdo_sqlite' => ['debian' => [], 'alpine' => []],
        'pdo' => ['debian' => [], 'alpine' => []],
        'curl' => ['debian' => [], 'alpine' => []],
        'iconv' => ['debian' => [], 'alpine' => []],
        'session' => ['debian' => [], 'alpine' => []],
    ];

    /** @var array<string, array{debian: list<string>, alpine: list<string>, error?: string, version: string}> */
    private const PECL = [
        'redis' => ['debian' => [], 'alpine' => [], 'version' => '6.2.0'],
        'apcu' => ['debian' => [], 'alpine' => [], 'version' => '5.1.24'],
        'xdebug' => ['debian' => [], 'alpine' => ['linux-headers'], 'error' => "configure: error: Xdebug requires the 'linux/…' headers", 'version' => '3.4.4'],
        'amqp' => ['debian' => ['librabbitmq-dev'], 'alpine' => ['rabbitmq-c-dev'], 'error' => 'configure: error: Please reinstall the rabbitmq-c distribution', 'version' => '2.1.2'],
        'imagick' => ['debian' => ['libmagickwand-dev'], 'alpine' => ['imagemagick-dev'], 'error' => 'configure: error: not found MagickWand.h', 'version' => '3.8.0'],
        'mongodb' => ['debian' => [], 'alpine' => [], 'version' => '2.1.1'],
        'uuid' => ['debian' => ['uuid-dev'], 'alpine' => ['util-linux-dev'], 'error' => 'configure: error: Please reinstall the libuuid distribution', 'version' => '1.2.1'],
        'yaml' => ['debian' => ['libyaml-dev'], 'alpine' => ['yaml-dev'], 'error' => 'configure: error: Please install libyaml', 'version' => '2.2.4'],
        'igbinary' => ['debian' => [], 'alpine' => [], 'version' => '3.2.16'],
        'pcov' => ['debian' => [], 'alpine' => [], 'version' => '1.0.12'],
    ];

    /**
     * Bibliothèque partagée dont l'extension compilée a besoin pour se charger (retirée par un
     * « apk del .build-deps » trop gourmand : « Unable to load dynamic library »).
     */
    public const RUNTIME = [
        'intl' => ['alpine' => 'icu-libs', 'debian' => 'libicu76', 'so' => 'libicuio.so.76'],
        'zip' => ['alpine' => 'libzip', 'debian' => 'libzip5', 'so' => 'libzip.so.5'],
        'pdo_pgsql' => ['alpine' => 'libpq', 'debian' => 'libpq5', 'so' => 'libpq.so.5'],
        'pgsql' => ['alpine' => 'libpq', 'debian' => 'libpq5', 'so' => 'libpq.so.5'],
        'gd' => ['alpine' => 'libpng', 'debian' => 'libpng16-16', 'so' => 'libpng16.so.16'],
        'xsl' => ['alpine' => 'libxslt', 'debian' => 'libxslt1.1', 'so' => 'libxslt.so.1'],
        'gmp' => ['alpine' => 'gmp', 'debian' => 'libgmp10', 'so' => 'libgmp.so.10'],
        'amqp' => ['alpine' => 'rabbitmq-c', 'debian' => 'librabbitmq4', 'so' => 'librabbitmq.so.4'],
        'imagick' => ['alpine' => 'imagemagick-libs', 'debian' => 'libmagickwand-7.q16-10', 'so' => 'libMagickWand-7.Q16HDRI.so.10'],
        'mbstring' => ['alpine' => 'oniguruma', 'debian' => '', 'so' => 'libonig.so.5'],
    ];

    /** @return array{debian: list<string>, alpine: list<string>, error?: string}|null */
    public static function core(string $name): ?array
    {
        return self::CORE[strtolower($name)] ?? null;
    }

    /** @return array{debian: list<string>, alpine: list<string>, error?: string, version: string}|null */
    public static function pecl(string $name): ?array
    {
        return self::PECL[strtolower($name)] ?? null;
    }

    /** Nom sous lequel l'extension apparaît dans php -m. */
    public static function moduleName(string $name): string
    {
        return match (strtolower($name)) {
            'pdo' => 'PDO',
            'opcache' => 'Zend OPcache',
            'xdebug' => 'Xdebug',
            default => strtolower($name),
        };
    }

    /**
     * Parmi les paquets requis, ceux qui manquent. Une entrée « a|b » est satisfaite par l'un ou l'autre.
     *
     * @param list<string> $required
     * @param list<string> $installed
     *
     * @return list<string>
     */
    public static function missing(array $required, array $installed): array
    {
        $missing = [];
        foreach ($required as $requirement) {
            $alternatives = explode('|', $requirement);
            if (!array_intersect($alternatives, $installed)) {
                $missing[] = $alternatives[0];
            }
        }

        return $missing;
    }
}
