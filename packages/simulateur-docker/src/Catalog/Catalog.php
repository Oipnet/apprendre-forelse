<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Catalog;

/**
 * Le « registre » du simulateur : un catalogue fermé d'images officielles, celles qu'un développeur
 * PHP rencontre (php, composer, nginx, postgres, mysql, mariadb, redis, node, alpine, debian…).
 * Une image inconnue produit la même erreur que Docker Hub : dépôt inexistant ou manifeste introuvable.
 */
final class Catalog
{
    /** Dernière version corrective de chaque PHP, telle que php -v l'affiche dans l'image. */
    private const PHP_PATCH = ['7.4' => '7.4.33', '8.0' => '8.0.30', '8.1' => '8.1.33', '8.2' => '8.2.29', '8.3' => '8.3.24', '8.4' => '8.4.11', '8.5' => '8.5.4'];
    private const PHP_LATEST = '8.5';
    private const PHP_BINARIES = ['php', 'php-config', 'phpize', 'pear', 'pecl', 'docker-php-entrypoint', 'docker-php-ext-install', 'docker-php-ext-enable', 'docker-php-ext-configure', 'docker-php-source'];
    private const SHELL_BINARIES = ['sh', 'ls', 'cat', 'cp', 'mv', 'rm', 'mkdir', 'rmdir', 'touch', 'echo', 'printf', 'chmod', 'chown', 'ln', 'sed', 'grep', 'find', 'tar', 'gzip', 'gunzip', 'head', 'tail', 'wc', 'sort', 'uniq', 'cut', 'tr', 'env', 'export', 'set', 'true', 'false', 'test', 'sleep', 'id', 'whoami', 'pwd', 'cd', 'date', 'uname', 'nproc', 'which', 'tee', 'xargs', 'basename', 'dirname', 'readlink', 'realpath', 'md5sum', 'sha256sum', 'hostname', 'awk', 'diff', 'du', 'df', 'ps', 'kill', 'umask', 'exit', 'stat', 'seq', 'chgrp', 'install', 'mktemp', 'sync', 'less', 'more', 'file', 'nproc'];
    private const DEBIAN_BINARIES = ['bash', 'dash', 'apt-get', 'apt', 'dpkg', 'apt-cache', 'useradd', 'usermod', 'groupadd', 'userdel', 'adduser', 'addgroup', 'deluser', 'update-ca-certificates', 'getent', 'su', 'gpg', 'xz', 'bzip2', 'perl'];
    private const ALPINE_BINARIES = ['apk', 'adduser', 'addgroup', 'deluser', 'wget', 'update-ca-certificates', 'xz', 'bzip2', 'ping', 'nc', 'nslookup', 'getent', 'su', 'unzip', 'ash'];
    /** Une image publiée « il y a quelques semaines », pour la colonne CREATED. */
    private const PUBLISHED = 3 * 7 * 86400;

    /**
     * Résout une référence d'image (php, php:8.4-apache, docker.io/library/php:8.4, ghcr.io/x/y:1).
     *
     * @throws UnknownImageException
     */
    public function resolve(string $reference): BaseImage
    {
        [$repository, $tag] = self::split($reference);
        $image = $this->build($repository, $tag);
        if ($image === null) {
            throw $this->knowsRepository($repository)
                ? UnknownImageException::manifest($repository, $tag)
                : UnknownImageException::repository($repository, $tag);
        }

        return $image;
    }

    /** @return array{string, string} dépôt normalisé (sans docker.io/library/), tag (latest par défaut) */
    public static function split(string $reference): array
    {
        $reference = preg_replace('#@sha256:[0-9a-f]+$#', '', $reference) ?? $reference;
        $repository = $reference;
        $tag = 'latest';
        $slash = strrpos($reference, '/');
        $colon = strrpos($reference, ':');
        if ($colon !== false && ($slash === false || $colon > $slash)) {
            $repository = substr($reference, 0, $colon);
            $tag = substr($reference, $colon + 1);
        }
        $repository = preg_replace('#^(docker\.io/|index\.docker\.io/)#', '', $repository) ?? $repository;
        $repository = preg_replace('#^library/#', '', $repository) ?? $repository;

        return [$repository, $tag === '' ? 'latest' : $tag];
    }

    /** Le nom complet tel que BuildKit l'affiche : docker.io/library/php:8.4-apache. */
    public static function canonical(string $repository, string $tag): string
    {
        $full = str_contains($repository, '/') ? $repository : 'library/'.$repository;
        if (!preg_match('#^[a-z0-9.-]+\.[a-z]+/#', $full)) {
            $full = 'docker.io/'.$full;
        }

        return $full.':'.$tag;
    }

    private function knowsRepository(string $repository): bool
    {
        return \in_array($repository, ['php', 'composer', 'nginx', 'postgres', 'mysql', 'mariadb', 'redis', 'alpine', 'debian', 'ubuntu', 'node', 'busybox', 'hello-world', 'axllent/mailpit', 'mailhog/mailhog', 'adminer', 'phpmyadmin', 'caddy', 'dunglas/frankenphp', 'memcached', 'rabbitmq', 'traefik', 'nginxinc/nginx-unprivileged'], true);
    }

    private function build(string $repository, string $tag): ?BaseImage
    {
        return match ($repository) {
            'php' => $this->php($tag),
            'composer' => $this->composer($tag),
            'nginx' => $this->nginx($tag),
            'nginxinc/nginx-unprivileged' => $this->nginx($tag, unprivileged: true),
            'postgres' => $this->postgres($tag),
            'mysql' => $this->mysql($tag),
            'mariadb' => $this->mariadb($tag),
            'redis' => $this->redis($tag),
            'alpine' => $this->alpine($tag),
            'debian' => $this->debian($tag),
            'ubuntu' => $this->ubuntu($tag),
            'node' => $this->node($tag),
            'busybox' => $tag === 'latest' || preg_match('/^1\.\d+/', $tag) ? $this->image('busybox', $tag, 'alpine', 'busybox', 4_300_000, 1, cmd: ['sh']) : null,
            'hello-world' => $tag === 'latest' || $tag === 'linux' ? $this->image('hello-world', $tag, 'alpine', 'hello', 13_000, 1, cmd: ['/hello'], binaries: []) : null,
            'axllent/mailpit' => $tag === 'latest' || preg_match('/^v1(\.\d+)*$/', $tag) ? $this->image('axllent/mailpit', $tag, 'alpine', 'mailpit', 33_000_000, 4, cmd: [], entrypoint: ['/mailpit'], exposed: [1025, 1110, 8025], docroot: '/mailpit-ui', files: ['/mailpit' => 24_000_000, '/mailpit-ui/index.html' => self::placeholderPage('Mailpit', 'Boîte de réception de test — aucun message pour le moment.'), '/mailpit-ui/livez' => "ok\n", '/mailpit-ui/readyz' => "ok\n"], binaries: [...self::SHELL_BINARIES, ...self::ALPINE_BINARIES, 'mailpit'], healthcheck: ['test' => ['CMD', '/mailpit', 'readyz'], 'interval' => '15s', 'startPeriod' => '10s']) : null,
            'mailhog/mailhog' => $tag === 'latest' || preg_match('/^v1/', $tag) ? $this->image('mailhog/mailhog', $tag, 'alpine', 'mailpit', 392_000_000, 4, cmd: [], entrypoint: ['MailHog'], exposed: [1025, 8025], docroot: '/mailhog-ui', files: ['/mailhog-ui/index.html' => self::placeholderPage('MailHog', 'Boîte de réception de test — aucun message pour le moment.')], user: 'mailhog') : null,
            'adminer' => $tag === 'latest' || preg_match('/^[45](\.\d+)*(-standalone|-fastcgi)?$/', $tag) ? $this->image('adminer', $tag, 'alpine', 'static-web', 255_000_000, 12, cmd: ['php', '-S', '[::]:8080', '-t', '/var/www/html'], entrypoint: ['entrypoint.sh', 'docker-php-entrypoint'], exposed: [8080], docroot: '/var/www/html', files: ['/var/www/html/index.html' => self::placeholderPage('Adminer', 'Connexion à la base de données (simulateur : formulaire non fonctionnel).')], phpVersion: '8.4.11', binaries: [...self::SHELL_BINARIES, ...self::ALPINE_BINARIES, ...self::PHP_BINARIES], user: 'adminer') : null,
            'phpmyadmin' => $tag === 'latest' || preg_match('/^5(\.\d+)*(-apache|-fpm|-fpm-alpine)?$/', $tag) ? $this->image('phpmyadmin', $tag, 'debian', 'static-web', 570_000_000, 20, cmd: ['apache2-foreground'], entrypoint: ['/docker-entrypoint.sh'], exposed: [80], docroot: '/var/www/html', files: ['/var/www/html/index.html' => self::placeholderPage('phpMyAdmin', 'Bienvenue dans phpMyAdmin (simulateur : page d\'accueil seulement).')], phpVersion: '8.3.24') : null,
            'caddy' => $tag === 'latest' || preg_match('/^2(\.\d+)*(-alpine|-builder)?$/', $tag) ? $this->image('caddy', $tag, 'alpine', 'static-web', 50_000_000, 5, cmd: ['caddy', 'run', '--config', '/etc/caddy/Caddyfile', '--adapter', 'caddyfile'], exposed: [80, 443, 2019], docroot: '/usr/share/caddy', files: ['/usr/share/caddy/index.html' => self::placeholderPage('Caddy works!', 'Page par défaut de l\'image caddy.'), '/etc/caddy/Caddyfile' => ":80 {\n\troot * /usr/share/caddy\n\tfile_server\n}\n"], binaries: ['caddy', ...self::SHELL_BINARIES]) : null,
            'dunglas/frankenphp' => $this->frankenphp($tag),
            'memcached' => $tag === 'latest' || preg_match('/^1(\.\d+)*(-alpine)?$/', $tag) ? $this->image('memcached', $tag, str_contains($tag, 'alpine') ? 'alpine' : 'debian', 'redis', 90_000_000, 6, cmd: ['memcached'], entrypoint: ['docker-entrypoint.sh'], exposed: [11211], user: 'memcache') : null,
            'rabbitmq' => $tag === 'latest' || preg_match('/^[34](\.\d+)*(-management)?(-alpine)?$/', $tag) ? $this->image('rabbitmq', $tag, str_contains($tag, 'alpine') ? 'alpine' : 'debian', 'static-web', 260_000_000, 10, cmd: ['rabbitmq-server'], entrypoint: ['docker-entrypoint.sh'], exposed: str_contains($tag, 'management') ? [4369, 5671, 5672, 15671, 15672, 15691, 15692, 25672] : [4369, 5671, 5672, 15691, 15692, 25672], volumes: ['/var/lib/rabbitmq'], docroot: str_contains($tag, 'management') ? '/rabbitmq-ui' : null, files: str_contains($tag, 'management') ? ['/rabbitmq-ui/index.html' => self::placeholderPage('RabbitMQ Management', 'Interface de gestion (simulateur).')] : []) : null,
            'traefik' => $tag === 'latest' || preg_match('/^v?[23](\.\d+)*$/', $tag) ? $this->image('traefik', $tag, 'alpine', 'static-web', 200_000_000, 4, cmd: ['traefik'], entrypoint: ['/entrypoint.sh'], exposed: [80], docroot: '/traefik-ui', files: ['/traefik-ui/index.html' => self::placeholderPage('Traefik', 'Tableau de bord (simulateur).')]) : null,
            default => null,
        };
    }

    private function php(string $tag): ?BaseImage
    {
        if ($tag === 'latest') {
            $tag = self::PHP_LATEST.'-cli';
        }
        if (!preg_match('/^(\d\.\d)(?:\.\d+)?(?:-(cli|fpm|apache|zts))?(?:-(bookworm|bullseye|trixie|alpine(?:\d+\.\d+)?))?$/', $tag, $m)) {
            return null;
        }
        $minor = $m[1];
        if (!isset(self::PHP_PATCH[$minor])) {
            return null;
        }
        $variant = $m[2] ?? 'cli';
        $osTag = $m[3] ?? '';
        $alpine = str_starts_with($osTag, 'alpine');
        if ($alpine && $variant === 'apache') {
            return null; // pas d'image php:*-apache-alpine : Debian seulement
        }
        $os = $alpine ? 'alpine' : 'debian';
        $version = self::PHP_PATCH[$minor];
        $kind = match ($variant) { 'apache' => 'apache-php', 'fpm' => 'php-fpm', default => 'php-cli' };
        $size = match ($variant) { 'apache' => 540_000_000, 'fpm' => 530_000_000, default => 520_000_000 };
        if ($alpine) {
            $size = $variant === 'fpm' ? 120_000_000 : 110_000_000;
        }
        $env = [
            'PHPIZE_DEPS' => $alpine ? implode(' ', Packages::PHPIZE_DEPS_ALPINE) : 'autoconf dpkg-dev file g++ gcc libc-dev make pkg-config re2c',
            'PHP_INI_DIR' => '/usr/local/etc/php',
            'PHP_VERSION' => $version,
            'PHP_CFLAGS' => '-fstack-protector-strong -fpic -fpie -O2 -D_LARGEFILE_SOURCE -D_FILE_OFFSET_BITS=64',
        ];
        $files = [
            '/usr/local/etc/php/php.ini-development' => self::PHP_INI_DEVELOPMENT,
            '/usr/local/etc/php/php.ini-production' => self::PHP_INI_PRODUCTION,
            '/usr/local/etc/php/conf.d/docker-php-ext-sodium.ini' => "extension=sodium\n",
        ];
        // Les images php installent curl (et tar, xz) avant de compiler PHP : il reste dans l'image.
        $binaries = [...self::SHELL_BINARIES, ...self::PHP_BINARIES, ...($alpine ? self::ALPINE_BINARIES : self::DEBIAN_BINARIES), 'curl'];
        $cmd = ['php', '-a'];
        $entrypoint = ['docker-php-entrypoint'];
        $exposed = [];
        $docroot = null;
        $workdir = null;
        if ($variant === 'apache') {
            $env['APACHE_CONFDIR'] = '/etc/apache2';
            $env['APACHE_ENVVARS'] = '/etc/apache2/envvars';
            $cmd = ['apache2-foreground'];
            $exposed = [80];
            $docroot = '/var/www/html';
            $workdir = '/var/www/html';
            $binaries = [...$binaries, 'apache2', 'apache2ctl', 'apachectl', 'a2enmod', 'a2dismod', 'a2ensite', 'a2dissite', 'a2enconf', 'a2disconf', 'apache2-foreground'];
            $files += [
                '/etc/apache2/ports.conf' => "# If you just change the port or add more ports here, you will likely also\n# have to change the VirtualHost statement in\n# /etc/apache2/sites-enabled/000-default.conf\n\nListen 80\n\n<IfModule ssl_module>\n\tListen 443\n</IfModule>\n\n<IfModule mod_gnutls.c>\n\tListen 443\n</IfModule>\n",
                '/etc/apache2/apache2.conf' => self::APACHE_CONF,
                '/etc/apache2/sites-available/000-default.conf' => self::APACHE_VHOST,
                '/etc/apache2/sites-enabled/000-default.conf' => self::APACHE_VHOST,
                '/etc/apache2/conf-available/docker-php.conf' => self::APACHE_DOCKER_PHP_CONF,
                '/etc/apache2/conf-enabled/docker-php.conf' => self::APACHE_DOCKER_PHP_CONF,
                '/etc/apache2/mods-available/rewrite.load' => "LoadModule rewrite_module /usr/lib/apache2/modules/mod_rewrite.so\n",
                '/etc/apache2/mods-available/headers.load' => "LoadModule headers_module /usr/lib/apache2/modules/mod_headers.so\n",
                '/etc/apache2/mods-available/expires.load' => "LoadModule expires_module /usr/lib/apache2/modules/mod_expires.so\n",
            ];
        } elseif ($variant === 'fpm') {
            $cmd = ['php-fpm'];
            $exposed = [9000];
            $workdir = '/var/www/html';
            $binaries = [...$binaries, 'php-fpm'];
            $files += [
                '/usr/local/etc/php-fpm.conf' => ";;;;;;;;;;;;;;;;;;;;;\n; FPM Configuration ;\n;;;;;;;;;;;;;;;;;;;;;\n\n; All relative paths in this configuration file are relative to PHP's install\n; prefix (/usr/local).\n\n[global]\n; Pid file\n;pid = run/php-fpm.pid\n\n; Error log file\n;error_log = log/php-fpm.log\n\n; Send FPM to background. Set to 'no' to keep FPM in foreground for debugging.\n;daemonize = yes\n\n;;;;;;;;;;;;;;;;;;;;\n; Pool Definitions ;\n;;;;;;;;;;;;;;;;;;;;\n\n; To configure the pools it is recommended to have one .conf file per\n; pool in the following directory:\ninclude=etc/php-fpm.d/*.conf\n",
                '/usr/local/etc/php-fpm.conf.default' => ";;;;;;;;;;;;;;;;;;;;;\n; FPM Configuration ;\n;;;;;;;;;;;;;;;;;;;;;\n\n[global]\n;pid = run/php-fpm.pid\n;error_log = log/php-fpm.log\n;daemonize = yes\n\ninclude=NONE/etc/php-fpm.d/*.conf\n",
                '/usr/local/etc/php-fpm.d/docker.conf' => "[global]\nerror_log = /proc/self/fd/2\n\n; https://github.com/docker-library/php/pull/725#issuecomment-443540114\nlog_limit = 8192\n\n[www]\n; php-fpm closes STDOUT on startup, so sending logs to /proc/self/fd/1 does not work.\n; https://bugs.php.net/bug.php?id=73886\naccess.log = /proc/self/fd/2\n\nclear_env = no\n\n; Ensure worker stdout and stderr are sent to the main error log.\ncatch_workers_output = yes\ndecorate_workers_output = no\n",
                '/usr/local/etc/php-fpm.d/www.conf' => "[www]\nuser = www-data\ngroup = www-data\nlisten = 127.0.0.1:9000\npm = dynamic\npm.max_children = 5\npm.start_servers = 2\npm.min_spare_servers = 1\npm.max_spare_servers = 3\n",
                '/usr/local/etc/php-fpm.d/www.conf.default' => "[www]\nuser = www-data\ngroup = www-data\nlisten = 127.0.0.1:9000\npm = dynamic\npm.max_children = 5\npm.start_servers = 2\npm.min_spare_servers = 1\npm.max_spare_servers = 3\n",
                '/usr/local/etc/php-fpm.d/zz-docker.conf' => "[global]\ndaemonize = no\n\n[www]\nlisten = 9000\n",
            ];
        }

        return $this->image('php', $tag, $os, $kind, $size, $alpine ? 10 : 14, cmd: $cmd, entrypoint: $entrypoint, exposed: $exposed, workdir: $workdir, env: $env, binaries: $binaries, phpExtensions: PhpExtensions::BUILTIN, files: $files, phpVersion: $version, docroot: $docroot);
    }

    private function composer(string $tag): ?BaseImage
    {
        if (!preg_match('/^(latest|lts|2(\.\d+){0,2}|1(\.\d+){0,2})$/', $tag)) {
            return null;
        }
        $version = str_starts_with($tag, '1') ? '1.10.27' : (preg_match('/^2\.\d+\.\d+$/', $tag) ? $tag : '2.8.10');

        return $this->image('composer', $tag, 'alpine', 'composer', 190_000_000, 16,
            cmd: ['composer'], entrypoint: ['/docker-entrypoint.sh'], workdir: '/app',
            env: ['COMPOSER_ALLOW_SUPERUSER' => '1', 'COMPOSER_HOME' => '/tmp', 'COMPOSER_VERSION' => $version, 'PHP_VERSION' => '8.4.11', 'PHP_INI_DIR' => '/usr/local/etc/php'],
            binaries: [...self::SHELL_BINARIES, ...self::PHP_BINARIES, ...self::ALPINE_BINARIES, 'composer', 'git', 'unzip', 'zip', 'curl', 'bash', 'openssh-client', 'patch', 'subversion', 'tini'],
            phpExtensions: [...PhpExtensions::BUILTIN, 'zip'],
            files: ['/usr/bin/composer' => 3_140_000, '/usr/local/etc/php/php.ini-development' => self::PHP_INI_DEVELOPMENT, '/usr/local/etc/php/php.ini-production' => self::PHP_INI_PRODUCTION],
            phpVersion: '8.4.11');
    }

    /**
     * nginx, ou sa variante « unprivileged » (nginxinc/nginx-unprivileged) : même serveur, lancé sous
     * l'utilisateur nginx, sur le port 8080, avec son pid et ses fichiers temporaires dans /tmp.
     */
    private function nginx(string $tag, bool $unprivileged = false): ?BaseImage
    {
        if (!preg_match('/^(latest|stable|mainline|alpine|alpine-slim|stable-alpine|mainline-alpine|stable-alpine-slim|(1\.\d+(\.\d+)?)(-alpine|-alpine-slim|-bookworm|-perl)?)$/', $tag)) {
            return null;
        }
        $alpine = str_contains($tag, 'alpine');
        $version = preg_match('/^1\.\d+/', $tag, $m) ? $m[0] : '1.29';
        $files = [
            '/etc/nginx/nginx.conf' => $unprivileged ? self::NGINX_UNPRIVILEGED_CONF : self::NGINX_CONF,
            '/etc/nginx/conf.d/default.conf' => $unprivileged ? str_replace(['listen       80;', 'listen  [::]:80;'], ['listen       8080;', 'listen  [::]:8080;'], self::NGINX_DEFAULT_CONF) : self::NGINX_DEFAULT_CONF,
            // Livrés par l'image : « include fastcgi_params; » les charge pour de vrai. fastcgi.conf pose
            // en plus SCRIPT_FILENAME ; fastcgi_params ne le pose pas, il faut l'écrire soi-même.
            '/etc/nginx/fastcgi_params' => '
fastcgi_param  QUERY_STRING       $query_string;
fastcgi_param  REQUEST_METHOD     $request_method;
fastcgi_param  CONTENT_TYPE       $content_type;
fastcgi_param  CONTENT_LENGTH     $content_length;
fastcgi_param  SCRIPT_NAME        $fastcgi_script_name;
fastcgi_param  REQUEST_URI        $request_uri;
fastcgi_param  DOCUMENT_URI       $document_uri;
fastcgi_param  DOCUMENT_ROOT      $document_root;
fastcgi_param  SERVER_PROTOCOL    $server_protocol;
fastcgi_param  REQUEST_SCHEME     $scheme;
fastcgi_param  HTTPS              $https if_not_empty;

fastcgi_param  GATEWAY_INTERFACE  CGI/1.1;
fastcgi_param  SERVER_SOFTWARE    nginx/$nginx_version;

fastcgi_param  REMOTE_ADDR        $remote_addr;
fastcgi_param  REMOTE_PORT        $remote_port;
fastcgi_param  SERVER_ADDR        $server_addr;
fastcgi_param  SERVER_PORT        $server_port;
fastcgi_param  SERVER_NAME        $server_name;

# PHP only, required if PHP was built with --enable-force-cgi-redirect
fastcgi_param  REDIRECT_STATUS    200;
',
            '/etc/nginx/fastcgi.conf' => '
fastcgi_param  QUERY_STRING       $query_string;
fastcgi_param  REQUEST_METHOD     $request_method;
fastcgi_param  CONTENT_TYPE       $content_type;
fastcgi_param  CONTENT_LENGTH     $content_length;
fastcgi_param  SCRIPT_FILENAME    $document_root$fastcgi_script_name;
fastcgi_param  SCRIPT_NAME        $fastcgi_script_name;
fastcgi_param  REQUEST_URI        $request_uri;
fastcgi_param  DOCUMENT_URI       $document_uri;
fastcgi_param  DOCUMENT_ROOT      $document_root;
fastcgi_param  SERVER_PROTOCOL    $server_protocol;
fastcgi_param  REQUEST_SCHEME     $scheme;
fastcgi_param  HTTPS              $https if_not_empty;

fastcgi_param  GATEWAY_INTERFACE  CGI/1.1;
fastcgi_param  SERVER_SOFTWARE    nginx/$nginx_version;

fastcgi_param  REMOTE_ADDR        $remote_addr;
fastcgi_param  REMOTE_PORT        $remote_port;
fastcgi_param  SERVER_ADDR        $server_addr;
fastcgi_param  SERVER_PORT        $server_port;
fastcgi_param  SERVER_NAME        $server_name;

# PHP only, required if PHP was built with --enable-force-cgi-redirect
fastcgi_param  REDIRECT_STATUS    200;
',
            '/etc/nginx/scgi_params' => '
scgi_param  REQUEST_METHOD     $request_method;
scgi_param  REQUEST_URI        $request_uri;
scgi_param  QUERY_STRING       $query_string;
scgi_param  CONTENT_TYPE       $content_type;

scgi_param  DOCUMENT_URI       $document_uri;
scgi_param  DOCUMENT_ROOT      $document_root;
scgi_param  SCGI               1;
scgi_param  SERVER_PROTOCOL    $server_protocol;
scgi_param  REQUEST_SCHEME     $scheme;
scgi_param  HTTPS              $https if_not_empty;

scgi_param  REMOTE_ADDR        $remote_addr;
scgi_param  REMOTE_PORT        $remote_port;
scgi_param  SERVER_PORT        $server_port;
scgi_param  SERVER_NAME        $server_name;
',
            '/etc/nginx/uwsgi_params' => '
uwsgi_param  QUERY_STRING       $query_string;
uwsgi_param  REQUEST_METHOD     $request_method;
uwsgi_param  CONTENT_TYPE       $content_type;
uwsgi_param  CONTENT_LENGTH     $content_length;

uwsgi_param  REQUEST_URI        $request_uri;
uwsgi_param  PATH_INFO          $document_uri;
uwsgi_param  DOCUMENT_ROOT      $document_root;
uwsgi_param  SERVER_PROTOCOL    $server_protocol;
uwsgi_param  REQUEST_SCHEME     $scheme;
uwsgi_param  HTTPS              $https if_not_empty;

uwsgi_param  REMOTE_ADDR        $remote_addr;
uwsgi_param  REMOTE_PORT        $remote_port;
uwsgi_param  SERVER_PORT        $server_port;
uwsgi_param  SERVER_NAME        $server_name;
',
            '/etc/nginx/mime.types' => "types {\n    text/html html htm;\n    text/css css;\n    application/javascript js;\n    image/png png;\n    image/jpeg jpeg jpg;\n    image/svg+xml svg;\n}\n",
            '/usr/share/nginx/html/index.html' => self::NGINX_WELCOME,
            '/usr/share/nginx/html/50x.html' => "<!DOCTYPE html>\n<html>\n<head>\n<title>Error</title>\n</head>\n<body>\n<h1>An error occurred.</h1>\n</body>\n</html>\n",
        ];

        return $this->image($unprivileged ? 'nginxinc/nginx-unprivileged' : 'nginx', $tag, $alpine ? 'alpine' : 'debian', 'nginx', $alpine ? (str_contains($tag, 'slim') ? 12_000_000 : 52_000_000) : 192_000_000, $alpine ? 8 : 7,
            cmd: ['nginx', '-g', 'daemon off;'], entrypoint: ['/docker-entrypoint.sh'], exposed: [$unprivileged ? 8080 : 80],
            env: ['NGINX_VERSION' => $version.'.0'], binaries: [...self::SHELL_BINARIES, 'nginx', ...($alpine ? self::ALPINE_BINARIES : [...self::DEBIAN_BINARIES, 'curl'])],
            files: $files, docroot: '/usr/share/nginx/html', user: $unprivileged ? 'nginx' : null,
            // La variante unprivileged donne à nginx sa configuration et son cache ; l'image officielle les laisse à root.
            owners: $unprivileged
                ? ['/var/cache/nginx' => 'nginx', '/etc/nginx' => 'nginx', '/etc/nginx/conf.d' => 'nginx', '/run' => 'root']
                : ['/var/cache/nginx' => 'root', '/run' => 'root']);
    }

    private function postgres(string $tag): ?BaseImage
    {
        if (!preg_match('/^(latest|(1[3-8])(\.\d+)?(-alpine(\d+\.\d+)?|-bookworm|-trixie)?)$/', $tag, $m)) {
            return null;
        }
        $major = (int) ($m[2] ?? 18);
        $alpine = str_contains($tag, 'alpine');
        // PostgreSQL 18 range ses données un cran plus haut : /var/lib/postgresql (et non plus …/data).
        $volume = $major >= 18 ? '/var/lib/postgresql' : '/var/lib/postgresql/data';

        return $this->image('postgres', $tag, $alpine ? 'alpine' : 'debian', 'postgres', $alpine ? 280_000_000 : 440_000_000, $alpine ? 10 : 14,
            cmd: ['postgres'], entrypoint: ['docker-entrypoint.sh'], exposed: [5432], volumes: [$volume],
            env: ['PG_MAJOR' => (string) $major, 'PG_VERSION' => $major.'.'.($major >= 18 ? 0 : 6), 'PGDATA' => $volume.($major >= 18 ? '/'.$major.'/docker' : ''), 'LANG' => 'en_US.utf8'],
            binaries: [...self::SHELL_BINARIES, 'postgres', 'psql', 'pg_dump', 'pg_restore', 'pg_isready', 'initdb', 'createdb', 'gosu', ...($alpine ? self::ALPINE_BINARIES : self::DEBIAN_BINARIES)],
            user: null, files: []);
    }

    private function mysql(string $tag): ?BaseImage
    {
        if (!preg_match('/^(latest|lts|innovation|(8\.[04]|8|9|9\.\d+)(\.\d+)?(-oracle|-debian|-oraclelinux9)?)$/', $tag, $m)) {
            return null;
        }
        $version = str_starts_with($tag, '9') || $tag === 'innovation' ? '9.4.0' : (str_starts_with($tag, '8.0') ? '8.0.43' : '8.4.6');

        return $this->image('mysql', $tag, 'debian', 'mysql', 600_000_000, 12,
            cmd: ['mysqld'], entrypoint: ['docker-entrypoint.sh'], exposed: [3306, 33060], volumes: ['/var/lib/mysql'],
            env: ['MYSQL_MAJOR' => explode('.', $version)[0].'.'.explode('.', $version)[1], 'MYSQL_VERSION' => $version],
            binaries: [...self::SHELL_BINARIES, 'mysqld', 'mysql', 'mysqldump', 'mysqladmin', 'gosu', ...self::DEBIAN_BINARIES]);
    }

    private function mariadb(string $tag): ?BaseImage
    {
        if (!preg_match('/^(latest|lts|(10\.(6|11)|11(\.\d+)?)(\.\d+)?(-noble|-jammy|-ubi)?)$/', $tag)) {
            return null;
        }
        $version = str_starts_with($tag, '10.6') ? '10.6.22' : (str_starts_with($tag, '10.11') ? '10.11.13' : '11.8.3');

        return $this->image('mariadb', $tag, 'debian', 'mariadb', 400_000_000, 8,
            cmd: ['mariadbd'], entrypoint: ['docker-entrypoint.sh'], exposed: [3306], volumes: ['/var/lib/mysql'],
            env: ['MARIADB_VERSION' => $version], binaries: [...self::SHELL_BINARIES, 'mariadbd', 'mariadb', 'mysql', 'mariadb-dump', 'mysqldump', 'healthcheck.sh', 'gosu', ...self::DEBIAN_BINARIES]);
    }

    private function redis(string $tag): ?BaseImage
    {
        if (!preg_match('/^(latest|alpine|([678](\.\d+){0,2})(-alpine(\d+\.\d+)?|-bookworm)?)$/', $tag)) {
            return null;
        }
        $alpine = str_contains($tag, 'alpine');
        $version = preg_match('/^[678](\.\d+)?/', $tag, $m) ? $m[0] : '8.2';

        return $this->image('redis', $tag, $alpine ? 'alpine' : 'debian', 'redis', $alpine ? 41_000_000 : 118_000_000, $alpine ? 8 : 8,
            cmd: ['redis-server'], entrypoint: ['docker-entrypoint.sh'], exposed: [6379], volumes: ['/data'], workdir: '/data',
            env: ['REDIS_VERSION' => $version.(substr_count($version, '.') < 2 ? '.0' : '')], binaries: [...self::SHELL_BINARIES, 'redis-server', 'redis-cli', 'gosu', ...($alpine ? self::ALPINE_BINARIES : self::DEBIAN_BINARIES)]);
    }

    private function alpine(string $tag): ?BaseImage
    {
        if (!preg_match('/^(latest|edge|3\.(1[6-9]|2\d)(\.\d+)?)$/', $tag)) {
            return null;
        }

        return $this->image('alpine', $tag, 'alpine', 'shell', 8_300_000, 1, cmd: ['/bin/sh'], binaries: [...self::SHELL_BINARIES, ...self::ALPINE_BINARIES], files: ['/etc/os-release' => "NAME=\"Alpine Linux\"\nID=alpine\nVERSION_ID=3.22.1\nPRETTY_NAME=\"Alpine Linux v3.22\"\n"]);
    }

    private function debian(string $tag): ?BaseImage
    {
        if (!preg_match('/^(latest|stable|bookworm|bullseye|trixie|1[123](\.\d+)?)(-slim)?$/', $tag)) {
            return null;
        }
        $slim = str_ends_with($tag, '-slim');

        return $this->image('debian', $tag, 'debian', 'shell', $slim ? 75_000_000 : 117_000_000, 1, cmd: ['bash'], binaries: [...self::SHELL_BINARIES, ...self::DEBIAN_BINARIES], files: ['/etc/os-release' => "PRETTY_NAME=\"Debian GNU/Linux 13 (trixie)\"\nNAME=\"Debian GNU/Linux\"\nID=debian\n"]);
    }

    private function ubuntu(string $tag): ?BaseImage
    {
        if (!preg_match('/^(latest|jammy|noble|plucky|2[24]\.04|25\.04)$/', $tag)) {
            return null;
        }

        return $this->image('ubuntu', $tag, 'debian', 'shell', 78_000_000, 1, cmd: ['/bin/bash'], binaries: [...self::SHELL_BINARIES, ...self::DEBIAN_BINARIES], files: ['/etc/os-release' => "PRETTY_NAME=\"Ubuntu 24.04.3 LTS\"\nNAME=\"Ubuntu\"\nID=ubuntu\n"]);
    }

    private function node(string $tag): ?BaseImage
    {
        if (!preg_match('/^(latest|lts|current|(1[8-9]|2[0-4])(\.\d+){0,2})(-alpine(\d+\.\d+)?|-slim|-bookworm|-bookworm-slim|-trixie)?$/', $tag, $m)) {
            return null;
        }
        $alpine = str_contains($tag, 'alpine');
        $slim = str_contains($tag, 'slim');
        $major = $m[2] ?? '24';

        return $this->image('node', $tag, $alpine ? 'alpine' : 'debian', 'node', $alpine ? 160_000_000 : ($slim ? 220_000_000 : 1_100_000_000), $alpine ? 4 : ($slim ? 5 : 8),
            cmd: ['node'], entrypoint: ['docker-entrypoint.sh'], env: ['NODE_VERSION' => $major.'.0.0', 'YARN_VERSION' => '1.22.22'],
            binaries: [...self::SHELL_BINARIES, 'node', 'npm', 'npx', 'yarn', 'corepack', ...($alpine ? self::ALPINE_BINARIES : ($slim ? self::DEBIAN_BINARIES : [...self::DEBIAN_BINARIES, 'git', 'gcc', 'g++', 'make', 'python3']))]);
    }

    private function frankenphp(string $tag): ?BaseImage
    {
        if (!preg_match('/^(latest|1(\.\d+){0,2}|(1(\.\d+){0,2}-)?php8\.[2-5](\.\d+)?)(-alpine|-bookworm|-trixie)?$/', $tag)) {
            return null;
        }
        $alpine = str_contains($tag, 'alpine');
        $php = preg_match('/php(8\.\d)/', $tag, $m) ? self::PHP_PATCH[$m[1]] : self::PHP_PATCH['8.4'];

        return $this->image('dunglas/frankenphp', $tag, $alpine ? 'alpine' : 'debian', 'frankenphp', $alpine ? 200_000_000 : 620_000_000, 16,
            cmd: ['--config', '/etc/frankenphp/Caddyfile', '--adapter', 'caddyfile'], entrypoint: ['docker-php-entrypoint', 'frankenphp', 'run'], exposed: [80, 443, 2019, 443], workdir: '/app',
            env: ['PHP_VERSION' => $php, 'PHP_INI_DIR' => '/usr/local/etc/php', 'SERVER_NAME' => 'localhost', 'FRANKENPHP_VERSION' => '1.9.1', 'PHPIZE_DEPS' => 'autoconf dpkg-dev file g++ gcc libc-dev make pkg-config re2c'],
            binaries: [...self::SHELL_BINARIES, ...self::PHP_BINARIES, 'frankenphp', 'install-php-extensions', ...($alpine ? self::ALPINE_BINARIES : self::DEBIAN_BINARIES)],
            phpExtensions: PhpExtensions::BUILTIN, phpVersion: $php, docroot: '/app/public',
            files: ['/etc/frankenphp/Caddyfile' => "{\n\tfrankenphp\n}\n\n{\$SERVER_NAME:localhost} {\n\troot /app/public\n\tencode zstd br gzip\n\tphp_server\n}\n", '/usr/local/etc/php/php.ini-development' => self::PHP_INI_DEVELOPMENT, '/usr/local/etc/php/php.ini-production' => self::PHP_INI_PRODUCTION]);
    }

    /**
     * @param list<string>          $cmd
     * @param list<string>|null     $entrypoint
     * @param list<int>             $exposed
     * @param list<string>          $volumes
     * @param array<string,string>  $env
     * @param list<string>|null     $binaries
     * @param list<string>          $phpExtensions
     * @param array<string,string|int> $files
     */
    private function image(string $repository, string $tag, string $os, string $kind, int $size, int $layers, array $cmd = [], ?array $entrypoint = null, array $exposed = [], array $volumes = [], ?string $workdir = null, array $env = [], ?array $binaries = null, array $phpExtensions = [], array $files = [], ?string $phpVersion = null, ?string $docroot = null, ?string $user = null, array $owners = [], ?array $healthcheck = null): BaseImage
    {
        $env = ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'] + $env;
        $binaries ??= [...self::SHELL_BINARIES, ...($os === 'alpine' ? self::ALPINE_BINARIES : self::DEBIAN_BINARIES)];
        $files['/etc/hostname'] = "container\n";
        $created = time() - self::PUBLISHED - (crc32($repository.$tag) % (5 * 86400));

        return new BaseImage($repository, $tag, $os, $kind, $size, $layers, $env, $cmd, $entrypoint, $exposed, $volumes, $workdir, $user, array_values(array_unique($binaries)), $phpExtensions, $files, $phpVersion, $docroot, $created, $owners, $healthcheck);
    }

    private static function placeholderPage(string $title, string $text): string
    {
        return "<!DOCTYPE html>\n<html lang=\"fr\">\n<head><meta charset=\"utf-8\"><title>".htmlspecialchars($title)."</title>\n<style>body{font-family:system-ui,sans-serif;margin:4rem auto;max-width:40rem;color:#222}h1{font-weight:600}</style></head>\n<body><h1>".htmlspecialchars($title)."</h1><p>".htmlspecialchars($text)."</p></body>\n</html>\n";
    }

    private const PHP_INI_DEVELOPMENT = <<<'INI'
        ; php.ini-development : réglages recommandés pour le développement.
        ; (Extrait : le vrai fichier fait deux mille lignes.)
        [PHP]
        display_errors = On
        display_startup_errors = On
        error_reporting = E_ALL
        log_errors = On
        memory_limit = 128M
        max_execution_time = 30
        upload_max_filesize = 2M
        post_max_size = 8M
        date.timezone =
        [opcache]
        opcache.enable = 1
        opcache.validate_timestamps = 1

        INI;

    private const PHP_INI_PRODUCTION = <<<'INI'
        ; php.ini-production : réglages recommandés pour la production.
        ; (Extrait : le vrai fichier fait deux mille lignes.)
        [PHP]
        display_errors = Off
        display_startup_errors = Off
        error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT
        log_errors = On
        memory_limit = 128M
        max_execution_time = 30
        upload_max_filesize = 2M
        post_max_size = 8M
        expose_php = Off
        date.timezone =
        [opcache]
        opcache.enable = 1
        opcache.validate_timestamps = 1

        INI;

    private const APACHE_CONF = <<<'CONF'
        # Configuration principale d'Apache (extrait).
        ServerRoot "/etc/apache2"
        Mutex file:${APACHE_LOCK_DIR} default
        PidFile ${APACHE_PID_FILE}
        Timeout 300
        KeepAlive On
        User ${APACHE_RUN_USER}
        Group ${APACHE_RUN_GROUP}
        HostnameLookups Off
        ErrorLog ${APACHE_LOG_DIR}/error.log
        LogLevel warn
        IncludeOptional mods-enabled/*.load
        IncludeOptional mods-enabled/*.conf
        Include ports.conf
        <Directory />
        	Options FollowSymLinks
        	AllowOverride None
        	Require all denied
        </Directory>
        <Directory /usr/share>
        	AllowOverride None
        	Require all granted
        </Directory>
        <Directory /var/www/>
        	Options Indexes FollowSymLinks
        	AllowOverride None
        	Require all granted
        </Directory>
        AccessFileName .htaccess
        IncludeOptional conf-enabled/*.conf
        IncludeOptional sites-enabled/*.conf

        CONF;

    private const APACHE_VHOST = <<<'CONF'
        <VirtualHost *:80>
        	ServerAdmin webmaster@localhost
        	DocumentRoot /var/www/html

        	ErrorLog ${APACHE_LOG_DIR}/error.log
        	CustomLog ${APACHE_LOG_DIR}/access.log combined
        </VirtualHost>

        CONF;

    private const APACHE_DOCKER_PHP_CONF = <<<'CONF'
        <FilesMatch \.php$>
        	SetHandler application/x-httpd-php
        </FilesMatch>

        DirectoryIndex disabled
        DirectoryIndex index.php index.html

        <Directory /var/www/>
        	Options -Indexes
        	AllowOverride All
        </Directory>

        CONF;

    private const NGINX_CONF = <<<'CONF'
        user  nginx;
        worker_processes  auto;

        error_log  /var/log/nginx/error.log notice;
        pid        /run/nginx.pid;

        events {
            worker_connections  1024;
        }

        http {
            include       /etc/nginx/mime.types;
            default_type  application/octet-stream;
            log_format  main  '$remote_addr - $remote_user [$time_local] "$request" '
                              '$status $body_bytes_sent "$http_referer" '
                              '"$http_user_agent" "$http_x_forwarded_for"';
            access_log  /var/log/nginx/access.log  main;
            sendfile        on;
            keepalive_timeout  65;
            include /etc/nginx/conf.d/*.conf;
        }

        CONF;

    private const NGINX_UNPRIVILEGED_CONF = <<<'CONF'
        worker_processes  auto;

        error_log  /var/log/nginx/error.log notice;
        pid        /tmp/nginx.pid;

        events {
            worker_connections  1024;
        }

        http {
            proxy_temp_path /tmp/proxy_temp;
            client_body_temp_path /tmp/client_temp;
            fastcgi_temp_path /tmp/fastcgi_temp;
            uwsgi_temp_path /tmp/uwsgi_temp;
            scgi_temp_path /tmp/scgi_temp;

            include       /etc/nginx/mime.types;
            default_type  application/octet-stream;
            log_format  main  '$remote_addr - $remote_user [$time_local] "$request" '
                              '$status $body_bytes_sent "$http_referer" '
                              '"$http_user_agent" "$http_x_forwarded_for"';
            access_log  /var/log/nginx/access.log  main;
            sendfile        on;
            keepalive_timeout  65;
            include /etc/nginx/conf.d/*.conf;
        }

        CONF;

    private const NGINX_DEFAULT_CONF = <<<'CONF'
        server {
            listen       80;
            listen  [::]:80;
            server_name  localhost;

            location / {
                root   /usr/share/nginx/html;
                index  index.html index.htm;
            }

            error_page   500 502 503 504  /50x.html;
            location = /50x.html {
                root   /usr/share/nginx/html;
            }
        }

        CONF;

    private const NGINX_WELCOME = <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head>
        <title>Welcome to nginx!</title>
        <style>
        html { color-scheme: light dark; }
        body { width: 35em; margin: 0 auto;
        font-family: Tahoma, Verdana, Arial, sans-serif; }
        </style>
        </head>
        <body>
        <h1>Welcome to nginx!</h1>
        <p>If you see this page, the nginx web server is successfully installed and
        working. Further configuration is required.</p>

        <p>For online documentation and support please refer to
        <a href="http://nginx.org/">nginx.org</a>.<br/>
        Commercial support is available at
        <a href="http://nginx.com/">nginx.com</a>.</p>

        <p><em>Thank you for using nginx.</em></p>
        </body>
        </html>

        HTML;
}
