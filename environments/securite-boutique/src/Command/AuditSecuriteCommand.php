<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Un audit de dépendances hors ligne (chapitre 13). Il lit var/avis-securite.json,
 * une base d'avis figée livrée avec l'application, et rend un format proche de
 * « composer audit ». Correction pédagogique : brancher un vrai « composer audit »
 * et une veille (Dependabot / Renovate).
 */
#[AsCommand(name: 'app:securite:audit', description: 'Audit hors ligne des dépendances (base d\'avis figée).')]
final class AuditSecuriteCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fichier = $this->projectDir.'/var/avis-securite.json';
        if (!is_file($fichier)) {
            $output->writeln('<comment>Aucune base d\'avis (var/avis-securite.json).</comment>');

            return Command::SUCCESS;
        }

        /** @var array{advisories: array<string, list<array{title:string, cve:?string, link:string}>>} $data */
        $data = json_decode((string) file_get_contents($fichier), true, 512, JSON_THROW_ON_ERROR);
        $total = 0;
        foreach ($data['advisories'] as $paquet => $avis) {
            foreach ($avis as $a) {
                ++$total;
                $output->writeln(sprintf("<info>%s</info>\n  %s\n  %s  %s\n", $paquet, $a['title'], $a['cve'] ?? 'N/A', $a['link']));
            }
        }
        $output->writeln(sprintf('%d paquet(s) concerné(s) par un avis de sécurité.', $total));

        return $total > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
