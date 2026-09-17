<?php

namespace App\Command;

use App\Content\Check\LinkChecker;
use App\Content\ContentRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'content:links',
    description: 'Vérifie les liens de documentation des exercices et des fiches de cours (page accessible, ancre présente). Demande un accès réseau.',
)]
final class ContentLinksCommand
{
    public function __construct(
        private readonly ContentRepository $content,
        private readonly LinkChecker $checker,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Pack, parcours, parcours/exercice, pratique ou pratique/exercice à vérifier (tout par défaut)')] ?string $target = null,
    ): int {
        $usages = [];
        $exercises = $this->content->select($target);
        foreach ($exercises as $exercise) {
            foreach ($exercise->docs as $doc) {
                $usages[$doc->url][] = ($exercise->trackId ?? ContentRepository::PRACTICE).'/'.$exercise->id;
            }
            // La pull request d'origine d'un exercice de Pratique, affichée à la réussite.
            if (null === $exercise->trackId && null !== ($pullRequest = $this->content->findPractice($exercise->id)?->pullRequest)) {
                $usages[$pullRequest][] = ContentRepository::PRACTICE.'/'.$exercise->id;
            }
        }
        foreach ($this->content->chaptersOf($exercises) as [$track, $chapter]) {
            foreach ($chapter->lessonLinks() as $url) {
                $usages[$url][] = sprintf('%s/chapters/%s/lesson.md', $track->id, $chapter->id);
            }
        }
        if (!$usages) {
            $io->warning('Aucun lien de documentation à vérifier.');

            return Command::SUCCESS;
        }

        $broken = 0;
        foreach ($this->checker->check(array_keys($usages)) as $url => $problem) {
            if (null === $problem) {
                $io->writeln(sprintf(' <info>✔</info> %s', $url));
                continue;
            }
            ++$broken;
            $io->writeln(sprintf(' <error>✘</error> %s — <fg=red>%s</>', $url, $problem));
            $io->writeln('     utilisé par : '.implode(', ', $usages[$url]));
        }

        $io->newLine();
        if ($broken) {
            $io->error(sprintf('%d lien(s) cassé(s) sur %d.', $broken, \count($usages)));

            return Command::FAILURE;
        }
        $io->success(sprintf('%d lien(s) valides.', \count($usages)));

        return Command::SUCCESS;
    }
}
