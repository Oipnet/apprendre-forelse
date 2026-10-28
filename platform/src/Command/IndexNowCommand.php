<?php

namespace App\Command;

use App\Seo\IndexNow;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Envoie tout de suite à IndexNow ce que le planificateur enverrait à son prochain passage (voir IndexNow) : utile
 * après avoir posé la clé, ou pour voir ce qui partira (--liste).
 */
#[AsCommand(
    name: 'app:seo:indexnow',
    description: 'Envoie à IndexNow (Bing…) les pages nouvelles ou modifiées depuis le dernier envoi.',
)]
final readonly class IndexNowCommand
{
    public function __construct(
        private IndexNow $indexNow,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Lister les pages qui partiraient, sans rien envoyer')] bool $liste = false,
    ): int {
        if (!$this->indexNow->isEnabled()) {
            $io->warning('IndexNow est éteint : INDEXNOW_KEY vide ou invalide (8 à 128 lettres, chiffres ou tirets), ou SEARCH_INDEXING=0.');

            return Command::FAILURE;
        }
        $pending = $this->indexNow->pending();
        if ($liste) {
            $io->listing($pending);
            $io->writeln(sprintf('%d page(s) à envoyer.', \count($pending)));

            return Command::SUCCESS;
        }
        if ([] === $pending) {
            $io->success('Rien de nouveau à envoyer.');

            return Command::SUCCESS;
        }
        $submission = $this->indexNow->submit();
        if (null !== $submission->error) {
            if ($submission->submitted > 0) {
                $io->writeln(sprintf('%d page(s) envoyée(s) avant l\'échec.', $submission->submitted));
            }
            $io->error($submission->error);

            return Command::FAILURE;
        }
        $io->success(sprintf('%d page(s) envoyée(s) à IndexNow.', $submission->submitted));

        return Command::SUCCESS;
    }
}
