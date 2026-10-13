<?php

namespace App\Command;

use App\Verification\VerificationQueue;
use App\Verification\VerificationSandbox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Le service « verificateur » : rejoue, une par une, les réussites que le worker dépose dans VERIFICATIONS_DIR (voir
 * VerificationQueue), chacune dans le bac à sable de VerificationSandbox, et dépose le verdict à côté.
 *
 * Il tourne dans un conteneur sans réseau, sans base et sans secrets (deploy/compose.yaml) : le code rejoué est celui
 * des apprenants.
 */
#[AsCommand(
    name: 'app:verification:verificateur',
    description: 'Rejoue les réussites en attente de vérification (service verificateur, sans réseau ni secrets).',
)]
final readonly class VerifierCommand
{
    public function __construct(
        private VerificationQueue $queue,
        private VerificationSandbox $sandbox,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Traiter les demandes en attente, puis s\'arrêter')]
        bool $uneFois = false,
        #[Option('S\'arrêter après ce nombre de secondes (le conteneur redémarre)')]
        int $limite = 3600,
    ): int {
        $unavailable = $this->queue->isEnabled() ? $this->sandbox->unavailable() : 'VERIFICATIONS_DIR est vide : rien à vérifier.';
        if (null !== $unavailable) {
            $io->error($unavailable);
            // En service, on attend plutôt que de s'arrêter : un conteneur qui redémarre en boucle ferait échouer le
            // déploiement (docker compose up --wait) de tout le site. Les réussites restent en attente, et comptent.
            if (!$uneFois) {
                sleep(max(0, $limite));
            }

            return Command::FAILURE;
        }
        $fin = time() + $limite;
        do {
            while (null !== $request = $this->queue->pop()) {
                $result = $this->sandbox->run($request);
                $this->queue->pushResult($result);
                $io->writeln(sprintf('→ progression %d (%s) : %s%s', $request->progressId, $request->exerciseId, $result->status->label(), null === $result->detail ? '' : ' — '.$result->detail));
            }
            if ($uneFois) {
                break;
            }
            sleep(5);
        } while (time() < $fin);

        return Command::SUCCESS;
    }
}
