<?php

namespace App\Search;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Tient les index de Meilisearch à jour du contenu publié. Lancé toutes les dix minutes par le planificateur
 * (SearchSchedule, dans le worker), ou à la main (app:recherche:indexer) : un déploiement du contenu ne passe pas par
 * le moteur, et un exercice de Pratique programmé paraît à sa date, sans déploiement.
 *
 * Un passage sans changement ne réécrit rien : l'empreinte des documents envoyés est gardée (search.cache), et l'index
 * n'est réécrit que si elle change ou si son nombre de documents ne correspond plus (index perdu, recréé vide).
 */
final readonly class SearchIndexer
{
    /** À changer avec les réglages ci-dessous : l'index est alors réécrit au passage suivant. */
    private const string SETTINGS_VERSION = '1';

    /** Les langues de Meilisearch (ISO 639-3) pour les nôtres : découpage des mots et mots vides. */
    public const array LANGUAGES = ['fr' => 'fra', 'en' => 'eng'];

    public function __construct(
        private Meilisearch $meilisearch,
        private SearchCatalog $catalog,
        #[Autowire(service: 'search.cache')]
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler]
    public function onSchedule(IndexSearch $message): void
    {
        if (!$this->meilisearch->isEnabled()) {
            return;
        }
        try {
            $this->index();
        } catch (SearchUnavailable $exception) {
            $this->logger->warning($exception->getMessage().'. Nouvel essai au prochain passage.');
        }
    }

    /**
     * @param bool $force réécrire même sans changement
     *
     * @return array<string, int|null> par index, le nombre de documents envoyés, null si rien n'a changé
     *
     * @throws SearchUnavailable
     */
    public function index(bool $force = false): array
    {
        $report = [];
        foreach ($this->catalog->locales() as $locale) {
            $index = $this->meilisearch->indexName($locale);
            $documents = array_map(static fn (SearchDocument $document) => $document->toArray(), $this->catalog->documents($locale));
            $fingerprint = hash('xxh128', self::SETTINGS_VERSION.json_encode($documents));
            $item = $this->cache->getItem('search.'.$index);
            $ids = array_column($documents, 'id');

            $current = $this->meilisearch->documentIds($index);
            if (!$force && $item->isHit() && $item->get() === $fingerprint && \count($current) === \count($ids) && [] === array_diff($ids, $current)) {
                $report[$index] = null;
                continue;
            }

            // Réglages d'abord : Meilisearch traite les tâches d'un index dans l'ordre, les documents seront indexés avec.
            $this->meilisearch->updateSettings($index, self::settings($locale));
            $this->meilisearch->addDocuments($index, $documents);
            $this->meilisearch->deleteDocuments($index, array_values(array_diff($current, $ids)));
            $this->cache->save($item->set($fingerprint));
            $this->logger->info('Recherche : {count} page(s) envoyée(s) à l\'index {index}.', ['count' => \count($documents), 'index' => $index]);
            $report[$index] = \count($documents);
        }

        return $report;
    }

    /** @return array<string, mixed> */
    public static function settings(string $locale): array
    {
        return [
            // Par ordre d'importance : un mot trouvé dans le titre compte plus que dans le texte.
            'searchableAttributes' => ['title', 'keywords', 'label', 'summary', 'text'],
            'displayedAttributes' => ['id', 'type', 'title', 'url', 'label', 'summary', 'text'],
            'filterableAttributes' => ['type'],
            'sortableAttributes' => ['weight'],
            // Les règles par défaut, puis le poids du type pour départager (voir SearchDocument::WEIGHTS).
            'rankingRules' => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness', 'weight:desc'],
            'localizedAttributes' => [['attributePatterns' => ['*'], 'locales' => [self::LANGUAGES[$locale] ?? 'eng']]],
            'pagination' => ['maxTotalHits' => 200],
        ];
    }
}
