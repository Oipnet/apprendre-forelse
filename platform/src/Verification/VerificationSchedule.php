<?php

namespace App\Verification;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Le planificateur de la vérification, consommé par le worker (`messenger:consume async scheduler_verification`) :
 * les réussites en attente partent chaque heure (à h+05), les verdicts sont relevés toutes les cinq minutes.
 *
 * Horaires fixes (from) et non comptés depuis le démarrage : le worker redémarre toutes les heures (--time-limit), une
 * échéance « une heure après le démarrage » ne tomberait jamais. Avec état (stateful) : une échéance manquée pendant un
 * redémarrage part au démarrage suivant, une seule fois.
 */
#[AsSchedule('verification')]
final readonly class VerificationSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(
                RecurringMessage::every('1 hour', new RequestVerifications(), from: '00:05'),
                RecurringMessage::every('5 minutes', new CollectVerifications(), from: '00:00'),
            )
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}
