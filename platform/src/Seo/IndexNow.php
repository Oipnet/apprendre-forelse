<?php

namespace App\Seo;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * IndexNow : prévient Bing (et Yandex, Seznam, Naver…, qui partagent les envois) des pages nouvelles ou modifiées,
 * au lieu d'attendre leur prochain passage sur le sitemap. DuckDuckGo et Ecosia s'appuient sur l'index de Bing ;
 * Google n'utilise pas IndexNow.
 *
 * Lancé toutes les heures par le planificateur (IndexNowSchedule), ou à la main (app:seo:indexnow) : envoie les
 * adresses du sitemap dont la date a changé depuis le dernier envoi réussi. Une heure plutôt qu'au déploiement : un
 * exercice de Pratique programmé paraît à sa date, sans déploiement, et un déploiement du contenu ne passe pas par
 * le moteur. Le premier passage envoie tout le sitemap.
 *
 * Les dates envoyées sont gardées dans la base (indexnow.cache) : elles survivent aux déploiements. Un envoi refusé
 * n'est pas retenu, il repart au passage suivant.
 *
 * Éteint sans clé (INDEXNOW_KEY) ou sur une instance qui refuse l'indexation (SEARCH_INDEXING=0).
 */
final readonly class IndexNow
{
    public const string ENDPOINT = 'https://api.indexnow.org/indexnow';
    /** Limite du protocole par envoi. */
    public const int BATCH = 10000;
    /** Ce qu'accepte le protocole : 8 à 128 caractères, lettres, chiffres et tirets. */
    private const string KEY_PATTERN = '/^[a-zA-Z0-9-]{8,128}$/';
    private const string SENT = 'indexnow.sent';

    public function __construct(
        private Sitemap $sitemap,
        private HttpClientInterface $httpClient,
        #[Autowire(service: 'indexnow.cache')]
        private CacheItemPoolInterface $cache,
        private UrlGeneratorInterface $urls,
        private LoggerInterface $logger,
        #[Autowire(env: 'INDEXNOW_KEY')]
        private string $key,
        #[Autowire(env: 'bool:SEARCH_INDEXING')]
        private bool $indexing,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->indexing && 1 === preg_match(self::KEY_PATTERN, $this->key);
    }

    /** La clé, servie par /indexnow.txt : les moteurs la lisent pour s'assurer que l'envoi vient du site. */
    public function key(): ?string
    {
        return $this->isEnabled() ? $this->key : null;
    }

    #[AsMessageHandler]
    public function onSchedule(SubmitToIndexNow $message): int
    {
        return $this->submit()->submitted;
    }

    /**
     * Les adresses du sitemap pas encore envoyées à cette date.
     *
     * @return list<string>
     */
    public function pending(): array
    {
        return self::changed(array_column($this->sitemap->entries(), 'lastmod', 'loc'), $this->sent());
    }

    /** Les adresses envoyées (aucune si rien n'a changé ou si IndexNow est éteint), et la raison d'un échec. */
    public function submit(): IndexNowSubmission
    {
        if (!$this->isEnabled()) {
            return new IndexNowSubmission(0);
        }
        $entries = array_column($this->sitemap->entries(), 'lastmod', 'loc');
        $sent = $this->sent();
        $pending = self::changed($entries, $sent);

        $keyLocation = $this->urls->generate('app_indexnow_key', [], UrlGeneratorInterface::ABSOLUTE_URL);
        // Une commande lancée hors du démarrage de l'image (docker compose exec) sans DEFAULT_URI génère des adresses
        // locales : IndexNow les refuserait, mieux vaut le dire.
        if ([] !== $pending && \in_array(parse_url($keyLocation, \PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
            return new IndexNowSubmission(0, sprintf('Adresses locales (%s) : DEFAULT_URI doit valoir l\'adresse publique du site (APP_URL).', $keyLocation));
        }
        $submitted = 0;
        $error = null;
        foreach (array_chunk($pending, self::BATCH) as $urls) {
            if (null !== ($error = $this->post($keyLocation, $urls))) {
                break;
            }
            foreach ($urls as $url) {
                $sent[$url] = $entries[$url];
            }
            $submitted += \count($urls);
        }
        if ($submitted > 0) {
            // Les pages sorties du sitemap n'ont plus à être retenues.
            $this->cache->save($this->cache->getItem(self::SENT)->set(array_intersect_key($sent, $entries)));
            $this->logger->info('IndexNow : {count} adresse(s) envoyée(s).', ['count' => $submitted]);
        }

        return new IndexNowSubmission($submitted, $error);
    }

    /**
     * @param list<string> $urls
     *
     * @return string|null la raison du refus, null si IndexNow a reçu les adresses
     */
    private function post(string $keyLocation, array $urls): ?string
    {
        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, ['json' => [
                'host' => parse_url($keyLocation, \PHP_URL_HOST),
                'key' => $this->key,
                'keyLocation' => $keyLocation,
                'urlList' => $urls,
            ], 'timeout' => 30]);
            $status = $response->getStatusCode();
            // Le corps d'un refus dit ce qui ne va pas (« key not valid », « url not allowed »…) : lu sans lever d'exception.
            $body = 200 === $status || 202 === $status ? '' : mb_substr(trim($response->getContent(false)), 0, 300);
        } catch (ExceptionInterface $exception) {
            $error = sprintf('IndexNow injoignable : %s', $exception->getMessage());
            $this->logger->warning($error.'. Nouvel essai au prochain passage.');

            return $error;
        }
        // 200 : reçu ; 202 : reçu, clé pas encore vérifiée (premier envoi). 403 : clé introuvable sur le site ; 422 :
        // adresses d'un autre hôte ; 429 : trop d'envois.
        if (200 !== $status && 202 !== $status) {
            $error = sprintf('IndexNow a refusé l\'envoi (HTTP %d, hôte %s, clé à %s)%s', $status, parse_url($keyLocation, \PHP_URL_HOST), $keyLocation, '' === $body ? '' : ' : '.$body);
            $this->logger->warning($error.'. Nouvel essai au prochain passage.');

            return $error;
        }

        return null;
    }

    /**
     * @param array<string, string> $entries adresse => date du sitemap
     * @param array<string, string> $sent    adresse => date au dernier envoi
     *
     * @return list<string>
     */
    private static function changed(array $entries, array $sent): array
    {
        return array_keys(array_filter($entries, static fn (string $lastmod, string $loc) => ($sent[$loc] ?? null) !== $lastmod, \ARRAY_FILTER_USE_BOTH));
    }

    /** @return array<string, string> adresse => date du sitemap au dernier envoi réussi */
    private function sent(): array
    {
        $item = $this->cache->getItem(self::SENT);

        return $item->isHit() && \is_array($item->get()) ? $item->get() : [];
    }
}
