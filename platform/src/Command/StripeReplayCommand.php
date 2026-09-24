<?php

namespace App\Command;

use App\Entity\StripeEvent;
use App\Payment\StripeWebhook;
use App\Repository\StripeEventRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rejoue un événement Stripe journalisé (StripeEvent), ou tous ceux dont le traitement a échoué.
 * Sans risque de doublon : un achat déjà payé n'ouvre pas un second accès.
 * Chaque événement est traité à part : un échec (qui peut fermer l'EntityManager) n'empêche pas les suivants.
 */
#[AsCommand(name: 'app:stripe:rejouer', description: 'Retraite un événement Stripe reçu, ou tous ceux restés en échec.')]
final readonly class StripeReplayCommand
{
    public function __construct(
        private StripeEventRepository $events,
        private StripeWebhook $webhook,
        private ManagerRegistry $doctrine,
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
        foreach (array_map(static fn (StripeEvent $event): ?int => $event->getId(), $events) as $id) {
            // Relu à chaque tour : après un échec, l'EntityManager est neuf et ne connaît plus les objets chargés avant.
            $event = $this->events->find($id);
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
                // Rien de ce que l'événement a laissé à moitié fait ne doit partir avec le suivant.
                $this->doctrine->resetManager();
            }
        }

        return $failures ? Command::FAILURE : Command::SUCCESS;
    }
}
