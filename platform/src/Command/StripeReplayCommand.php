<?php

namespace App\Command;

use App\Entity\StripeEvent;
use App\Payment\StripeWebhook;
use App\Repository\StripeEventRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rejoue un événement Stripe journalisé (StripeEvent), ou tous ceux dont le traitement a échoué.
 * Sans risque de doublon : un achat déjà payé n'ouvre pas un second accès.
 */
#[AsCommand(name: 'app:stripe:rejouer', description: 'Retraite un événement Stripe reçu, ou tous ceux restés en échec.')]
final readonly class StripeReplayCommand
{
    public function __construct(
        private StripeEventRepository $events,
        private StripeWebhook $webhook,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Identifiant de l\'événement (evt_…) ; sans lui, tous ceux en échec')] ?string $evenement = null): int
    {
        $events = null !== $evenement
            ? array_filter([$this->events->findOneByEventId($evenement)])
            : $this->events->findBy(['processedAt' => null], ['receivedAt' => 'ASC']);
        if (!$events) {
            $io->success(null !== $evenement ? sprintf('Événement « %s » inconnu.', $evenement) : 'Aucun événement en échec.');

            return null !== $evenement ? Command::FAILURE : Command::SUCCESS;
        }

        $failures = 0;
        foreach ($events as $event) {
            \assert($event instanceof StripeEvent);
            if ($event->isProcessed()) {
                $io->writeln(sprintf(' · %s (%s) déjà traité le %s', $event->getEventId(), $event->getType(), $event->getProcessedAt()?->format('d/m/Y H:i')));
                continue;
            }
            try {
                $this->webhook->process($event);
                $io->writeln(sprintf(' <info>✔</info> %s (%s)', $event->getEventId(), $event->getType()));
            } catch (\Throwable $e) {
                ++$failures;
                $io->writeln(sprintf(' <error>✘</error> %s (%s) : %s', $event->getEventId(), $event->getType(), $e->getMessage()));
            }
        }

        return $failures ? Command::FAILURE : Command::SUCCESS;
    }
}
