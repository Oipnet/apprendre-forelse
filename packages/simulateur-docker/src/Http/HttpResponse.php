<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Http;

final class HttpResponse
{
    /**
     * @param array<string,string> $headers
     * @param string|null          $error   connexion impossible : refused, reset, empty, unresolved
     * @param list<string>         $trace   ce qui s'est passé (conteneur, serveur, script), pour les tests et le mentor
     */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string $body = '',
        public ?string $error = null,
        public array $trace = [],
        public ?string $container = null,
        public ?string $script = null,
    ) {
    }

    public static function failure(string $error, string $message, array $trace = []): self
    {
        return new self(0, [], '', $error, [...$trace, $message]);
    }

    public function ok(): bool
    {
        return $this->error === null && $this->status < 400;
    }

    public static function page(int $status, string $html, string $server, array $trace = []): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8', 'Server' => $server], $html, null, $trace);
    }
}
