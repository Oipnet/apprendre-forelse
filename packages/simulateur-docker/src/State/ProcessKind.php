<?php

declare(strict_types=1);

namespace Forelse\DockerSim\State;

/**
 * Ce qui tourne en PID 1 dans un conteneur. La valeur est celle écrite dans state.json.
 *
 * Une seule liste : l'arrêt (docker stop, docker kill -s TERM), le service HTTP et `docker top` la
 * lisent au lieu d'en recopier chacun une — les copies avaient divergé (mailpit enregistré « mail »
 * mais attendu « mailpit » à l'arrêt, frankenphp arrêté proprement par un signal mais tué par stop).
 */
enum ProcessKind: string
{
    case Apache = 'apache';
    case Nginx = 'nginx';
    case PhpFpm = 'php-fpm';
    /** php -S */
    case PhpServer = 'php-server';
    case FrankenPhp = 'frankenphp';
    /** Caddy, traefik, rabbitmq, supervisord, cron : un serveur de fichiers statiques suffit à la simulation. */
    case Static = 'static';
    /** Mailpit, MailHog */
    case Mail = 'mail';
    case Postgres = 'postgres';
    case Mysql = 'mysql';
    case Mariadb = 'mariadb';
    case Redis = 'redis';
    case Memcached = 'memcached';
    /** sleep infinity, tail -f, un shell interactif */
    case Idle = 'idle';
    /** Un script PHP qui tourne en boucle (php worker.php) */
    case Worker = 'worker';

    /**
     * Le processus gère-t-il SIGTERM ? Les serveurs s'arrêtent proprement (code 0). Un PID 1 sans
     * gestionnaire ignore le signal : Docker le tue au bout de 10 s (137), et `kill -s TERM` n'y fait rien.
     */
    public function stopsGracefully(): bool
    {
        return match ($this) {
            self::Idle, self::Worker => false,
            default => true,
        };
    }

    /** Ce que le processus écrit dans son journal en recevant SIGTERM. */
    public function stopLog(): string
    {
        return match ($this) {
            self::PhpFpm => sprintf("[%1\$s] NOTICE: Terminating ...\n[%1\$s] NOTICE: exiting, bye-bye!", gmdate('d-M-Y H:i:s')),
            self::Nginx => sprintf("%1\$s [notice] 1#1: signal 15 (SIGTERM) received, exiting\n%1\$s [notice] 1#1: exit", gmdate('Y/m/d H:i:s')),
            self::Apache => sprintf('[%s] [mpm_prefork:notice] [pid 1:tid 1] AH00169: caught SIGTERM, shutting down', gmdate('D M d H:i:s.u Y')),
            self::Postgres => sprintf("%1\$s UTC [1] LOG:  received fast shutdown request\n%1\$s UTC [1] LOG:  database system is shut down", gmdate('Y-m-d H:i:s.v')),
            default => '',
        };
    }
}
