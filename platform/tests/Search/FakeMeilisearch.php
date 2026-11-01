<?php

namespace App\Tests\Search;

use App\Search\Meilisearch;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Un faux Meilisearch, pour les tests : il note les requêtes reçues et répond ce qu'on lui dit, route par route
 * (« POST /multi-search »). Une route sans réponse prévue répond {} ; `$down` le rend injoignable.
 */
final class FakeMeilisearch
{
    /** @var list<array{method: string, path: string, body: mixed}> */
    public array $requests = [];
    /** @var array<string, array{0: int, 1: mixed}> « MÉTHODE /chemin » (sans la requête) => [statut, corps] */
    public array $responses = [];
    public bool $down = false;

    public function client(): Meilisearch
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if ($this->down) {
                return new MockResponse('', ['error' => 'Connexion refusée']);
            }
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $query = parse_url($url, \PHP_URL_QUERY);
            $this->requests[] = ['method' => $method, 'path' => $path.(null !== $query ? '?'.$query : ''), 'body' => isset($options['body']) ? json_decode($options['body'], true) : null];
            [$status, $body] = $this->responses[$method.' '.$path] ?? [200, new \stdClass()];

            return new MockResponse(json_encode($body), ['http_code' => $status]);
        });

        return new Meilisearch($http, 'http://recherche:7700', 'cle-de-test', 'site');
    }

    /** @return list<string> « MÉTHODE /chemin » de chaque requête reçue */
    public function calls(): array
    {
        return array_map(static fn (array $r) => $r['method'].' '.strtok($r['path'], '?'), $this->requests);
    }
}
