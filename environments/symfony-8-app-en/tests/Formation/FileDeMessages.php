<?php

namespace App\Tests\Formation;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Exercise test helper: the message queue, without starting a worker.
 *
 * consommerLaFile() ("consume the queue") does, in the test, what "bin/console messenger:consume async" would do.
 */
trait FileDeMessages
{
    protected function transport(string $nom = 'async'): TransportInterface
    {
        $transport = static::getContainer()->has('messenger.transport.'.$nom) ? static::getContainer()->get('messenger.transport.'.$nom) : null;
        static::assertInstanceOf(TransportInterface::class, $transport, sprintf('No "%s" transport is configured (framework.messenger.transports).', $nom));

        return $transport;
    }

    /** @return list<Envelope> the messages waiting in the queue (messagesEnFile: "messages in the queue") */
    protected function messagesEnFile(string $nom = 'async'): array
    {
        $transport = $this->transport($nom);
        $envelopes = [];
        // A retried message carries a delay: it only becomes visible once that delay has passed.
        // We wait a few milliseconds before concluding that the queue is empty.
        for ($essai = 0; $essai < 20; ++$essai) {
            // get() returns one message at a time: we go through the queue, acknowledging as we go.
            foreach ($this->parcourir($transport) as $envelope) {
                $envelopes[] = $envelope;
            }
            if ([] !== $envelopes) {
                break;
            }
            usleep(5000);
        }
        foreach ($envelopes as $envelope) {
            $transport->send($envelope);
        }

        return $envelopes;
    }

    /**
     * Handles the waiting messages and returns how many there were.
     */
    protected function consommerLaFile(string $nom = 'async'): int
    {
        $transport = $this->transport($nom);
        $bus = static::getContainer()->get(MessageBusInterface::class);
        $traites = 0;
        foreach ($this->parcourir($transport) as $envelope) {
            // The "received" stamp tells the bus to handle the message instead of queuing it again.
            $bus->dispatch($envelope->with(new ReceivedStamp($nom), new ConsumedByWorkerStamp()));
            ++$traites;
        }

        return $traites;
    }

    /**
     * Starts a real worker on the queue (lancerLeWorker: "start the worker"), retries and failure queue included.
     * The equivalent of "bin/console messenger:consume <transport>", in the test.
     *
     * $messages is the number of expected handlings (a message retried twice counts as three):
     * the worker stops as soon as it has done them, without waiting for its time limit.
     *
     * @return string the command output
     */
    protected function lancerLeWorker(string $nom = 'async', int $messages = 3, int $secondes = 2): string
    {
        $enAttente = $this->messagesEnFile($nom);
        $commande = (new Application(static::$kernel))->find('messenger:consume');
        // Building the console can reset the services, and so empty an in-memory queue.
        if ($enAttente && [] === iterator_to_array($this->transport($nom)->get())) {
            foreach ($enAttente as $envelope) {
                $this->transport($nom)->send($envelope);
            }
        }

        $tester = new CommandTester($commande);
        // --no-reset: without it, the worker empties the in-memory transports between two messages.
        $tester->execute(['receivers' => [$nom], '--limit' => $messages, '--time-limit' => $secondes, '--sleep' => 0.05, '--no-reset' => null]);

        return $tester->getDisplay();
    }

    /** @return iterable<Envelope> empties the queue, message by message */
    private function parcourir(TransportInterface $transport): iterable
    {
        while ([] !== $envelopes = iterator_to_array($transport->get())) {
            foreach ($envelopes as $envelope) {
                $transport->ack($envelope);
                yield $envelope;
            }
        }
    }
}
