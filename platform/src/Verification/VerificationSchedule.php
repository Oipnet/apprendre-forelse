<?php

namespace App\Verification;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Le planificateur de la vérification, consommé par le worker (`messenger:consume async scheduler_verification`) :
 * toutes les cinq minutes, les réussites en attente partent (à h+02, h+07…) et les verdicts sont relevés (à h+00, h+05…).
 * Un passage sans réussite en attente ne coûte qu'une requête : une réussite n'est envoyée qu'une fois (voir
 * VerificationDispatcher::RETRY_AFTER). Le certificat attend ces verdicts : une dizaine de minutes au plus.
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
                RecurringMessage::every('5 minutes', new RequestVerifications(), from: '00:02'),
                RecurringMessage::every('5 minutes', new CollectVerifications(), from: '00:00'),
            )
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}
