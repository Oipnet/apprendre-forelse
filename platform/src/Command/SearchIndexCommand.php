<?php

namespace App\Command;

use App\Search\Meilisearch;
use App\Search\SearchIndexer;
use App\Search\SearchUnavailable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Met tout de suite les index de la recherche à jour, comme le fait le planificateur toutes les dix minutes (voir
 * SearchIndexer) : utile au premier démarrage de Meilisearch, ou pour vérifier qu'il répond.
 */
#[AsCommand(
    name: 'app:recherche:indexer',
    description: 'Envoie à Meilisearch le contenu publié (exercices, articles, notions, parcours) pour la recherche du site.',
)]
final readonly class SearchIndexCommand
{
    public function __construct(
        private Meilisearch $meilisearch,
        private SearchIndexer $indexer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Tout renvoyer, même si rien n\'a changé')] bool $force = false,
    ): int {
        if (!$this->meilisearch->isEnabled()) {
            $io->warning('La recherche est éteinte : MEILISEARCH_URL est vide.');

            return Command::FAILURE;
        }
        try {
            $report = $this->indexer->index($force);
        } catch (SearchUnavailable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
        foreach ($report as $index => $count) {
            $io->writeln(null === $count ? sprintf('%s : rien de nouveau.', $index) : sprintf('%s : %d page(s) envoyée(s).', $index, $count));
        }
        $io->success('Index de la recherche à jour (Meilisearch les traite dans les secondes qui suivent).');

        return Command::SUCCESS;
    }
}
