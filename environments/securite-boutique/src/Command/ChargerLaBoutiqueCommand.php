<?php

namespace App\Command;

use App\Fixture\ChargeurDeBoutique;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:boutique:charger', description: 'Charge les données de la boutique (avec les traces de Houblon Noir par défaut).')]
final class ChargerLaBoutiqueCommand extends Command
{
    public function __construct(private readonly ChargeurDeBoutique $chargeur)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sans-traces', null, InputOption::VALUE_NONE, 'Charge une boutique crédible SANS l\'attaque.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $traces = !$input->getOption('sans-traces');
        $this->chargeur->charger($traces);
        $io->success($traces
            ? 'Boutique chargée, avec les traces de l\'attaque du 14 mars 2026.'
            : 'Boutique chargée, sans les traces de l\'attaque.');

        return Command::SUCCESS;
    }
}
