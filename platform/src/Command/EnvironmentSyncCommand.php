<?php

namespace App\Command;

use App\Instance\InstalledEnvironments;
use App\Instance\PackEnvironments;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Installe les environnements que les packs montés déclarent et que l'instance n'a pas.
 *
 * À lancer après avoir déposé un pack, et au démarrage du conteneur si l'instance l'a demandé
 * (ENVIRONMENTS_AUTO_INSTALL). Ne touche à rien de ce qui est déjà là.
 */
#[AsCommand(
    name: 'app:environnement:synchroniser',
    description: 'Installe les environnements d\'exécution déclarés par les packs et absents de cette instance.',
)]
final class EnvironmentSyncCommand
{
    public function __construct(
        private readonly PackEnvironments $packEnvironments,
        private readonly InstalledEnvironments $installed,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Lister ce qui serait installé, sans rien installer')]
        bool $simuler = false,
        #[Option('Réinstaller aussi ceux dont le pack a changé d\'adresse ou de référence')]
        bool $mettreAJour = false,
    ): int {
        if (!$this->installed->isEnabled()) {
            $io->error(sprintf('Aucun dossier d\'environnements installables : %s n\'existe pas ou n\'est pas écrivable.', $this->installed->directory()));
            $io->writeln('Montez un volume écrivable et pointez INSTALLED_ENVIRONMENTS_DIR dessus (voir le README).');

            return Command::FAILURE;
        }

        $aFaire = $this->packEnvironments->toInstall($mettreAJour);
        $aEmpaqueter = $this->packEnvironments->toBuild();
        if ([] === $aFaire && [] === $aEmpaqueter) {
            $io->success('Rien à faire : les packs ont tous leurs environnements, et tous sont empaquetés.');

            return Command::SUCCESS;
        }

        $io->listing([
            ...array_map(
                static fn ($environment) => sprintf('%s — à installer depuis %s (pack « %s »)', $environment->id, $environment->describeSource(), $environment->packId),
                $aFaire,
            ),
            ...array_map(
                static fn (string $id) => sprintf('%s — porté par un pack, à empaqueter', $id),
                $aEmpaqueter,
            ),
        ]);
        if ($simuler) {
            $io->note(sprintf('%d au total. Relancez sans --simuler pour le faire.', \count($aFaire) + \count($aEmpaqueter)));

            return Command::SUCCESS;
        }

        $io->warning('Empaqueter exécute le code de ces environnements sur ce serveur (composer install).');
        $resultat = $this->packEnvironments->synchronize($mettreAJour, $io->writeln(...));

        foreach ($resultat['failed'] as $id => $message) {
            $io->error(sprintf('Environnement « %s » : %s', $id, $message));
        }
        if ([] !== $resultat['installed']) {
            $io->success(sprintf('Prêt : %s.', implode(', ', $resultat['installed'])));
        }

        return [] === $resultat['failed'] ? Command::SUCCESS : Command::FAILURE;
    }
}
