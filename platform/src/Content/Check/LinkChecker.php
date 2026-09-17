<?php

namespace App\Content\Check;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Vérifie les liens de documentation des exercices : la page répond, et l'ancre (#…) existe.
 *
 * La documentation bouge : une page renommée ou une section réécrite casse un lien sans bruit.
 */
final class LinkChecker
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @param list<string> $urls
     *
     * @return array<string, string|null> par URL : le problème, ou null si le lien est bon
     */
    public function check(array $urls): array
    {
        // Une requête par page (sans ancre), lancées ensemble : le client HTTP les parallélise.
        $responses = [];
        foreach (array_unique(array_map(static fn (string $url) => strtok($url, '#'), $urls)) as $page) {
            $responses[$page] = $this->httpClient->request('GET', $page, ['timeout' => 15, 'max_redirects' => 5]);
        }

        $pages = [];
        foreach ($responses as $page => $response) {
            try {
                $status = $response->getStatusCode();
                $pages[$page] = 200 === $status ? ['html' => $response->getContent()] : ['error' => sprintf('HTTP %d', $status)];
            } catch (ExceptionInterface $e) {
                $pages[$page] = ['error' => $e->getMessage()];
            }
        }

        $results = [];
        foreach ($urls as $url) {
            $page = $pages[strtok($url, '#')];
            $anchor = parse_url($url, \PHP_URL_FRAGMENT);
            $results[$url] = match (true) {
                isset($page['error']) => $page['error'],
                null === $anchor || '' === $anchor => null,
                // id="env", id='env', ou id=env (HTML minifié, attributs sans guillemets) — mais pas id=env_file.
                (bool) preg_match('/\b(?:id|name)=(["\']?)'.preg_quote($anchor, '/').'\1(?=[\s>\/"\']|$)/', $page['html']) => null,
                default => sprintf('ancre « #%s » introuvable dans la page', $anchor),
            };
        }

        return $results;
    }
}
