<?php

namespace App\Scratch;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Le planificateur du bac à sable, consommé par le worker (`messenger:consume … scheduler_scratch`) : une fois par
 * heure, le code des liens courts expirés est effacé de la base. Un lien expiré ne s'ouvre déjà plus (ScratchShortLinks::find).
 */
#[AsSchedule('scratch')]
final readonly class ScratchSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
        private ScratchShortLinks $links,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('1 hour', new PurgeScratchShares()))
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }

    #[AsMessageHandler]
    public function purge(PurgeScratchShares $message): void
    {
        $this->links->purge();
    }
}
