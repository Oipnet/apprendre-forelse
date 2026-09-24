<?php

namespace App\Command;

use App\Instance\EnvironmentBuildQueue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Le service « empaqueteur » : traite les demandes d'installation et d'empaquetage déposées par la
 * plateforme (voir EnvironmentBuildQueue), dans un conteneur sans secrets ni accès à la base.
 *
 * Chaque demande tourne dans son propre processus, avec la sortie dans les journaux du conteneur. Au
 * démarrage, ENVIRONMENTS_AUTO_INSTALL=1 empaquette les environnements que les packs portent.
 */
#[AsCommand(
    name: 'app:environnement:empaqueteur',
    description: 'Traite les demandes d\'installation d\'environnements (service empaqueteur, sans secrets).',
)]
final class EnvironmentBuilderCommand
{
    public function __construct(
        private readonly EnvironmentBuildQueue $queue,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire(env: 'bool:ENVIRONMENTS_AUTO_INSTALL')]
        private readonly bool $autoInstall = false,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Traiter les demandes en attente, puis s\'arrêter')]
        bool $uneFois = false,
        #[Option('S\'arrêter après ce nombre de secondes (le conteneur redémarre)')]
        int $limite = 3600,
    ): int {
        if (!$uneFois && $this->autoInstall) {
            $this->run($io, 'app:environnement:synchroniser', []);
        }
        $fin = time() + $limite;
        do {
            while (null !== $demande = $this->queue->pop()) {
                $this->run($io, $demande['command'], $demande['arguments']);
            }
            if ($uneFois) {
                break;
            }
            sleep(2);
        } while (time() < $fin);

        return Command::SUCCESS;
    }

    /** @param list<string> $arguments */
    private function run(SymfonyStyle $io, string $command, array $arguments): void
    {
        $io->writeln(sprintf('→ %s %s', $command, implode(' ', $arguments)));
        $php = (new PhpExecutableFinder())->find() ?: 'php';
        // Tableau d'arguments, jamais de shell : l'adresse du dépôt vient d'un formulaire.
        $process = new Process([$php, $this->projectDir.'/bin/console', $command, ...$arguments, '--no-interaction'], $this->projectDir, timeout: null);
        $process->run(static fn (string $type, string $sortie) => $io->write($sortie));
        $io->writeln($process->isSuccessful() ? '✓ terminé' : sprintf('✗ échec (code %d)', $process->getExitCode()));
    }
}
