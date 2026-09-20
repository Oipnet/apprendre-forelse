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
 * Outil des tests d'exercices : la file de messages, sans lancer de worker.
 *
 * consommerLaFile() fait, dans le test, ce que ferait « bin/console messenger:consume async ».
 */
trait FileDeMessages
{
    protected function transport(string $nom = 'async'): TransportInterface
    {
        $transport = static::getContainer()->has('messenger.transport.'.$nom) ? static::getContainer()->get('messenger.transport.'.$nom) : null;
        static::assertInstanceOf(TransportInterface::class, $transport, sprintf('Aucun transport « %s » n\'est configuré (framework.messenger.transports).', $nom));

        return $transport;
    }

    /** @return list<Envelope> les messages qui attendent dans la file */
    protected function messagesEnFile(string $nom = 'async'): array
    {
        $transport = $this->transport($nom);
        $envelopes = [];
        // Un message réessayé porte un délai : il n'est visible qu'une fois ce délai écoulé.
        // On laisse passer quelques millisecondes avant de conclure que la file est vide.
        for ($essai = 0; $essai < 20; ++$essai) {
            // get() ne rend qu'un message à la fois : on parcourt la file en acquittant au fur et à mesure.
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
     * Traite les messages en attente et renvoie leur nombre.
     */
    protected function consommerLaFile(string $nom = 'async'): int
    {
        $transport = $this->transport($nom);
        $bus = static::getContainer()->get(MessageBusInterface::class);
        $traites = 0;
        foreach ($this->parcourir($transport) as $envelope) {
            // Le tampon « reçu » dit au bus de traiter le message au lieu de le renvoyer en file.
            $bus->dispatch($envelope->with(new ReceivedStamp($nom), new ConsumedByWorkerStamp()));
            ++$traites;
        }

        return $traites;
    }

    /**
     * Lance un vrai worker sur la file : réessais et file des échecs compris.
     * Équivalent de « bin/console messenger:consume <transport> », dans le test.
     *
     * $messages est le nombre de traitements attendus (un message réessayé deux fois en vaut trois) :
     * le worker s'arrête dès qu'il les a faits, sans attendre sa limite de temps.
     *
     * @return string la sortie de la commande
     */
    protected function lancerLeWorker(string $nom = 'async', int $messages = 3, int $secondes = 2): string
    {
        $enAttente = $this->messagesEnFile($nom);
        $commande = (new Application(static::$kernel))->find('messenger:consume');
        // Construire la console peut réinitialiser les services, donc vider une file en mémoire.
        if ($enAttente && [] === iterator_to_array($this->transport($nom)->get())) {
            foreach ($enAttente as $envelope) {
                $this->transport($nom)->send($envelope);
            }
        }

        $tester = new CommandTester($commande);
        // --no-reset : sans cela, le worker vide les transports en mémoire entre deux messages.
        $tester->execute(['receivers' => [$nom], '--limit' => $messages, '--time-limit' => $secondes, '--sleep' => 0.05, '--no-reset' => null]);

        return $tester->getDisplay();
    }

    /** @return iterable<Envelope> vide la file, message par message */
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
