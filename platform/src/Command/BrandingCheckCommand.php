<?php

namespace App\Command;

use App\Instance\Branding;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:marque:verifier',
    description: 'Vérifie le marque.yaml de l\'instance (BRANDING_DIR) en entier, et dit quelle clé corriger.',
)]
final class BrandingCheckCommand
{
    public function __construct(
        private readonly Branding $branding,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $config = $this->branding->config();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success($config->isDefault
            ? 'Aucun marque.yaml : c\'est la marque du moteur qui est servie.'
            : sprintf('Marque « %s » valable (%s/%s).', $config->name, $config->directory, Branding::FILE));

        return Command::SUCCESS;
    }
}
