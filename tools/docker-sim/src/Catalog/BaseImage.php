<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Catalog;

/**
 * Une image de base connue du simulateur, avec ce qu'il faut pour se comporter comme la vraie :
 * son système (paquets apk ou apt), ses binaires, ses extensions PHP, ses fichiers notables,
 * et ce que fait son processus principal quand on la lance.
 */
final class BaseImage
{
    /**
     * @param 'alpine'|'debian'     $os
     * @param list<string>          $binaries      commandes disponibles dans un RUN ou un exec
     * @param list<string>          $phpExtensions extensions compilées (images php)
     * @param array<string,string>  $env
     * @param list<string>          $cmd
     * @param list<string>|null     $entrypoint
     * @param list<int>             $exposed       ports EXPOSE de l'image
     * @param list<string>          $volumes       volumes anonymes déclarés (VOLUME)
     * @param array<string,string|int> $files        fichiers notables (chemin => contenu, ou taille seule)
     * @param 'apache-php'|'nginx'|'php-fpm'|'php-cli'|'composer'|'postgres'|'mysql'|'mariadb'|'redis'|'shell'|'node'|'mailpit'|'adminer'|'caddy'|'hello'|'static-web'|'busybox' $kind
     */
    public function __construct(
        public readonly string $repository,
        public readonly string $tag,
        public readonly string $os,
        public readonly string $kind,
        public readonly int $sizeBytes,
        public readonly int $layers,
        public readonly array $env,
        public readonly array $cmd,
        public readonly ?array $entrypoint,
        public readonly array $exposed,
        public readonly array $volumes,
        public readonly ?string $workdir,
        public readonly ?string $user,
        public readonly array $binaries,
        public readonly array $phpExtensions,
        public readonly array $files,
        public readonly ?string $phpVersion,
        /** Dossier servi par le serveur web de l'image (apache, nginx), s'il y en a un. */
        public readonly ?string $docroot,
        /** Date de publication simulée (« 3 weeks ago » dans docker images). */
        public readonly int $createdAt,
        /** Dossiers qui existent dans l'image, avec leur propriétaire (/var/cache/nginx => nginx). @var array<string,string> */
        public readonly array $owners = [],
        /** La vigie déclarée par l'image (HEALTHCHECK). @var array{test: list<string>, interval?: string}|null */
        public readonly ?array $healthcheck = null,
    ) {
    }

    public function reference(): string
    {
        return $this->repository.':'.$this->tag;
    }

    /** Empreinte stable, calculée depuis la référence : toujours la même d'une session à l'autre. */
    public function digest(): string
    {
        return 'sha256:'.hash('sha256', 'forelse-docker-sim/'.$this->reference());
    }

    public function isPhp(): bool
    {
        return $this->phpVersion !== null;
    }
}
