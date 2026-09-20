<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Http;

final class HttpRequest
{
    /** @param array<string,string> $headers noms en minuscules */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $query = '',
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly string $clientIp = '172.17.0.1',
        public readonly string $host = 'localhost',
        public readonly int $port = 80,
        /** L'adresse demandée par le client, que les réécritures internes ne changent pas ($request_uri). */
        public readonly ?string $originalUri = null,
    ) {
    }

    /** Ce que nginx appelle $request_uri : l'URI d'origine, avant try_files et réécritures. */
    public function requestUri(): string
    {
        return $this->originalUri ?? $this->uri();
    }

    public static function fromUrl(string $method, string $uri, array $headers = [], string $body = '', string $clientIp = '172.17.0.1', string $host = 'localhost', int $port = 80): self
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $query = parse_url($uri, PHP_URL_QUERY) ?: '';

        return new self(strtoupper($method), rawurldecode($path), $query, array_change_key_case($headers, CASE_LOWER), $body, $clientIp, $host, $port);
    }

    public function uri(): string
    {
        return $this->path.($this->query !== '' ? '?'.$this->query : '');
    }

    public function withPath(string $path, ?string $query = null): self
    {
        return new self($this->method, $path, $query ?? $this->query, $this->headers, $this->body, $this->clientIp, $this->host, $this->port, $this->requestUri());
    }
}
