<?php

namespace App\Seo;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Le planificateur d'IndexNow, consommé par le worker (`messenger:consume … scheduler_indexnow`) : toutes les heures,
 * à h+10, les pages nouvelles ou modifiées partent (voir IndexNow). Un passage sans changement ne contacte personne.
 *
 * Horaires fixes et avec état, comme VerificationSchedule : le worker redémarre toutes les heures.
 */
#[AsSchedule('indexnow')]
final readonly class IndexNowSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('1 hour', new SubmitToIndexNow(), from: '00:10'))
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}
