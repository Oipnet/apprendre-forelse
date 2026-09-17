<?php

namespace App\Content;

/**
 * Requête d'exemple proposée dans l'onglet « Requêtes » du playground (API JSON, etc.).
 *
 * Le corps peut contenir {{ date:+N }} : remplacé par la date du jour + N jours (AAAA-MM-JJ),
 * pour que le contenu ne se périme pas.
 */
final readonly class ExampleRequest
{
    public const array METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $title,
        public string $method,
        public string $path,
        public array $headers = [],
        public ?string $body = null,
    ) {
    }
}
