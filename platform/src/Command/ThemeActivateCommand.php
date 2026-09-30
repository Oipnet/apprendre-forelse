<?php

namespace App\Command;

use App\Theme\ThemeActivation;
use App\Theme\ThemeActivationException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:theme:activer',
    description: 'Active un thème installé pour tous les visiteurs, comme la page « Thèmes » de l\'admin (déploiements scriptés).',
)]
final readonly class ThemeActivateCommand
{
    public function __construct(
        private ThemeActivation $activation,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Le thème : « default », « instance » ou le nom de son dossier dans THEMES_DIR')] string $theme,
    ): int {
        try {
            $this->activation->activate($theme);
        } catch (ThemeActivationException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(sprintf('Thème « %s » activé : le site l\'affiche dès la requête suivante.', $theme));

        return Command::SUCCESS;
    }
}
