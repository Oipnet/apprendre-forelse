<?php

namespace App\Search;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Le moteur de recherche du site : Meilisearch, un service à part (compose.yaml, « recherche »), joint par son API
 * HTTP. Un index par langue (MEILISEARCH_INDEX_fr, MEILISEARCH_INDEX_en). La clé ne quitte jamais le serveur : le
 * navigateur passe par la page /recherche.
 *
 * Éteint sans adresse (MEILISEARCH_URL vide) : pas de recherche, ni dans l'en-tête ni à l'adresse /recherche.
 */
final readonly class Meilisearch
{
    /** Une recherche plus lente que ça laisse la page dire « indisponible » plutôt que de la faire attendre. */
    private const float SEARCH_TIMEOUT = 3.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'MEILISEARCH_URL')]
        private string $url,
        #[Autowire(env: 'MEILISEARCH_KEY')]
        private string $key,
        #[Autowire(env: 'MEILISEARCH_INDEX')]
        private string $index,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== trim($this->url);
    }

    public function indexName(string $locale): string
    {
        return $this->index.'_'.$locale;
    }

    /**
     * Plusieurs recherches d'un coup (/multi-search) : les résultats, et les totaux par type sans filtre.
     *
     * @param list<array<string, mixed>> $queries chacune avec son « indexUid »
     *
     * @return list<array<string, mixed>> une réponse par recherche, dans le même ordre
     */
    public function multiSearch(array $queries, float $timeout = self::SEARCH_TIMEOUT): array
    {
        return $this->request('POST', '/multi-search', ['queries' => $queries], $timeout)['results'] ?? [];
    }

    /** @param array<string, mixed> $settings */
    public function updateSettings(string $index, array $settings): void
    {
        $this->request('PATCH', '/indexes/'.rawurlencode($index).'/settings', $settings);
    }

    /** @param list<array<string, mixed>> $documents ajoutés, ou remplacés s'ils existent (même id) */
    public function addDocuments(string $index, array $documents): void
    {
        if ([] !== $documents) {
            $this->request('POST', '/indexes/'.rawurlencode($index).'/documents?primaryKey=id', $documents);
        }
    }

    /** @param list<string> $ids */
    public function deleteDocuments(string $index, array $ids): void
    {
        if ([] !== $ids) {
            $this->request('POST', '/indexes/'.rawurlencode($index).'/documents/delete-batch', $ids);
        }
    }

    /** @return list<string> les identifiants des documents de l'index, aucun s'il n'existe pas encore */
    public function documentIds(string $index): array
    {
        $ids = [];
        $offset = 0;
        do {
            $page = $this->request('GET', '/indexes/'.rawurlencode($index).'/documents?fields=id&limit=1000&offset='.$offset, allowMissing: true);
            foreach ($page['results'] ?? [] as $document) {
                $ids[] = (string) $document['id'];
            }
            $offset += 1000;
        } while ($offset < (int) ($page['total'] ?? 0));

        return $ids;
    }

    /**
     * @param array<string, mixed>|list<mixed>|null $body
     *
     * @return array<string, mixed>
     *
     * @throws SearchUnavailable
     */
    private function request(string $method, string $path, ?array $body = null, float $timeout = 30, bool $allowMissing = false): array
    {
        $options = ['timeout' => $timeout, 'headers' => '' !== $this->key ? ['Authorization' => 'Bearer '.$this->key] : []];
        if (null !== $body) {
            $options['json'] = $body;
        }
        try {
            $response = $this->httpClient->request($method, rtrim($this->url, '/').$path, $options);
            $status = $response->getStatusCode();
            if ($allowMissing && 404 === $status) {
                return [];
            }
            if ($status >= 300) {
                $error = json_decode($response->getContent(false), true);
                throw new SearchUnavailable(sprintf('Meilisearch a refusé %s %s (HTTP %d)%s', $method, strtok($path, '?'), $status, \is_array($error) && isset($error['message']) ? ' : '.$error['message'] : ''));
            }

            return $response->toArray();
        } catch (ExceptionInterface $exception) {
            throw new SearchUnavailable(sprintf('Meilisearch injoignable (%s) : %s', $this->url, $exception->getMessage()), previous: $exception);
        }
    }
}
