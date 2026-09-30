<?php

namespace App\Command;

use App\Theme\Theme;
use App\Theme\ThemeLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:theme:verifier',
    description: 'Vérifie le theme.yaml de l\'instance (BRANDING_DIR) en entier, et dit quelle clé corriger.',
    // L'ancien nom, d'avant le renommage « marque » → « thème » : retiré en 4.0.
    aliases: ['app:marque:verifier'],
)]
final class ThemeCheckCommand
{
    public function __construct(
        private readonly Theme $theme,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $config = $this->theme->config();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($config->isDefault) {
            $io->success('Aucun theme.yaml : c\'est le thème du moteur qui est servi.');

            return Command::SUCCESS;
        }

        if ($this->theme->usesLegacyFile()) {
            $io->warning(sprintf('%s/%s : renommez ce fichier en %s. L\'ancien nom est encore lu, mais ne le sera plus en 4.0.', $config->directory, ThemeLoader::LEGACY_FILE, ThemeLoader::FILE));
        }
        $files = [
            'Feuilles'.($config->replacesEngineStyles ? ' (remplacent celle du moteur)' : '') => $config->stylesheets,
            'Scripts' => $config->scripts,
            'Polices préchargées' => $config->preload,
        ];
        foreach ($files as $label => $paths) {
            if ([] !== $paths) {
                $io->text($label.' :');
                $io->listing($paths);
            }
        }
        $io->success(sprintf('Thème « %s » valable (%s/%s).', $config->name, $config->directory, $config->file));

        return Command::SUCCESS;
    }
}
