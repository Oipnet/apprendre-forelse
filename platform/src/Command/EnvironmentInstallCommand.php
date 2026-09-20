<?php

namespace App\Command;

use App\Instance\EnvironmentInstaller;
use App\Instance\InstalledEnvironments;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Installe un environnement d'exécution depuis un dépôt Git.
 *
 * C'est aussi le travailleur de la page d'administration : elle lance cette commande en tâche de fond,
 * parce qu'un `composer install` dure des minutes et qu'une requête HTTP ne les attend pas.
 */
#[AsCommand(
    name: 'app:environnement:installer',
    description: 'Installe un environnement d\'exécution depuis un dépôt Git (clone, composer install, archive).',
)]
final class EnvironmentInstallCommand
{
    public function __construct(
        private readonly EnvironmentInstaller $installer,
        private readonly InstalledEnvironments $installed,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Adresse https du dépôt, ou l\'identifiant d\'un environnement déjà installé pour le mettre à jour')]
        string $depot,
        #[Option('Branche ou étiquette à installer (défaut : la branche par défaut du dépôt)')]
        string $ref = '',
    ): int {
        if (!$this->installed->isEnabled()) {
            $io->error(sprintf('Aucun dossier d\'environnements installables : %s n\'existe pas ou n\'est pas écrivable.', $this->installed->directory()));
            $io->writeln('Montez un volume écrivable et pointez INSTALLED_ENVIRONMENTS_DIR dessus (voir le README).');

            return Command::FAILURE;
        }

        try {
            $id = str_starts_with($depot, 'https://')
                ? $this->installer->install($depot, $ref, $io->writeln(...))
                : $this->installer->update($depot, $io->writeln(...));
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Environnement « %s » installé. Les exercices peuvent le désigner par « environment: %s ».', $id, $id));

        return Command::SUCCESS;
    }
}
