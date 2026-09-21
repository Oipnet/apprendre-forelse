<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell;

/**
 * Le réseau vu depuis un conteneur : résolution de noms (DNS de Docker), connexions TCP,
 * requêtes HTTP vers un autre conteneur (ou vers lui-même via localhost).
 */
interface Network
{
    /** Adresse IP du nom (service, conteneur, alias), ou null si le nom ne se résout pas. */
    public function resolve(string $host): ?string;

    /**
     * État d'une connexion TCP.
     *
     * @return array{status: 'open'|'refused'|'unresolved', process: ?string, container: ?\Forelse\DockerSim\State\Container}
     */
    public function connect(string $host, int $port): array;

    /**
     * @param array<string,string> $headers
     *
     * @return array{status: int, headers: array<string,string>, body: string}|array{error: 'unresolved'|'refused'|'reset'|'empty', message: string}
     */
    public function http(string $method, string $url, array $headers = [], string $body = ''): array;
}
