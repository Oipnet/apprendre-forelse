<?php

namespace App\Command;

use App\Instance\InstalledEnvironments;
use App\Instance\PackEnvironments;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Empaquette les environnements que les packs portent et dont l'archive manque.
 *
 * À lancer après avoir déposé un pack, et au démarrage du conteneur si l'instance l'a demandé
 * (ENVIRONMENTS_AUTO_INSTALL). Ne touche à rien de ce qui est déjà empaqueté.
 */
#[AsCommand(
    name: 'app:environnement:synchroniser',
    description: 'Empaquette les environnements d\'exécution portés par les packs et pas encore prêts.',
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
        #[Option('Lister ce qui serait empaqueté, sans rien faire')]
        bool $simuler = false,
    ): int {
        if (!$this->installed->isEnabled()) {
            $io->error(sprintf('Aucun dossier où déposer les archives : %s n\'existe pas ou n\'est pas écrivable.', $this->installed->directory()));
            $io->writeln('Montez un volume écrivable et pointez INSTALLED_ENVIRONMENTS_DIR dessus (voir le README).');

            return Command::FAILURE;
        }

        $aFaire = $this->packEnvironments->toBuild();
        if ([] === $aFaire) {
            $io->success('Rien à faire : les environnements portés par les packs sont tous empaquetés.');

            return Command::SUCCESS;
        }

        $io->listing($aFaire);
        if ($simuler) {
            $io->note(sprintf('%d à empaqueter. Relancez sans --simuler pour le faire.', \count($aFaire)));

            return Command::SUCCESS;
        }

        $io->warning('Empaqueter exécute le code de ces environnements sur ce serveur (composer install).');
        $resultat = $this->packEnvironments->synchronize($io->writeln(...));

        foreach ($resultat['failed'] as $id => $message) {
            $io->error(sprintf('Environnement « %s » : %s', $id, $message));
        }
        if ([] !== $resultat['built']) {
            $io->success(sprintf('Prêt : %s.', implode(', ', $resultat['built'])));
        }

        return [] === $resultat['failed'] ? Command::SUCCESS : Command::FAILURE;
    }
}
