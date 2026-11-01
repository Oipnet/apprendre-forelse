<?php

namespace App\Search;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Le planificateur de la recherche, consommé par le worker (`messenger:consume … scheduler_search`) : toutes les dix
 * minutes, les index de Meilisearch rattrapent le contenu publié (voir SearchIndexer). Sans changement, rien n'est réécrit.
 */
#[AsSchedule('search')]
final readonly class SearchSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('10 minutes', new IndexSearch()))
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}
