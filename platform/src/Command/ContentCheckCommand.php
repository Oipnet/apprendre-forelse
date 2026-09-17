<?php

namespace App\Command;

use App\Content\Check\ExerciseChecker;
use App\Content\ContentRepository;
use App\Version;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'content:check',
    description: 'Vérifie les exercices des packs : tests rouges au départ, verts avec la solution.',
)]
final class ContentCheckCommand
{
    public function __construct(
        private readonly ContentRepository $content,
        private readonly ExerciseChecker $checker,
        private readonly Version $version,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Pack, parcours, parcours/exercice, pratique ou pratique/exercice à vérifier (tout par défaut)')] ?string $target = null,
        #[Option('Conserver les projets reconstitués pour les inspecter')] bool $keep = false,
        #[Option('Ne vérifier qu\'un chapitre (son identifiant dans track.yaml)')] ?string $chapitre = null,
    ): int {
        $exercises = $this->content->select($target, $chapitre);
        if (!$exercises) {
            $io->error(match (true) {
                null !== $chapitre => sprintf('Aucun exercice dans le chapitre « %s » (chapitres connus : %s).', $chapitre, implode(', ', $this->content->chapterIds())),
                null === $target => 'Aucun exercice trouvé (voir CONTENT_PACKS_PATHS).',
                default => sprintf('Rien ne correspond à « %s ».', $target),
            });

            return Command::FAILURE;
        }

        // Quel moteur, et quels packs il a accepté de charger (voir la clé « moteur » de pack.yaml).
        $io->writeln(sprintf('Moteur <info>%s</info>', $this->version));
        $io->writeln(' Packs : '.implode(', ', array_map(
            static fn ($pack) => sprintf('%s %s%s', $pack->id, $pack->version, $pack->engine ? sprintf(' (moteur %s)', $pack->engine) : ''),
            $this->content->packs(),
        )));
        $io->newLine();

        $failures = 0;
        foreach ($exercises as $exercise) {
            $start = microtime(true);
            $result = $this->checker->check($exercise, $keep);
            $label = sprintf('%s/%s — %s', $exercise->trackId ?? ContentRepository::PRACTICE, $exercise->id, $exercise->title);
            $duration = sprintf('(%.1f s)', microtime(true) - $start);
            if ($result->isOk()) {
                $done = array_sum($result->objectivesBefore);
                $io->writeln(sprintf(' <info>✔</info> %s <comment>%s</comment> %d/%d objectifs déjà validés au départ', $label, $duration, $done, \count($result->objectivesBefore)));
            } else {
                ++$failures;
                $io->writeln(sprintf(' <error>✘</error> %s <comment>%s</comment>', $label, $duration));
            }
            foreach ($result->errors as $error) {
                $io->writeln('     <fg=red>'.$error.'</>');
            }
            foreach ($result->warnings as $warning) {
                $io->writeln('     <fg=yellow>'.$warning.'</>');
            }
            if ($result->workdir) {
                $io->writeln('     Projet conservé : '.$result->workdir);
            }
        }

        // Une fiche de cours manquante n'invalide pas le pack : on le signale seulement.
        foreach ($this->content->chaptersOf($exercises) as [$track, $chapter]) {
            if (!$chapter->hasLesson()) {
                $io->writeln(sprintf(' <fg=yellow>!</> %s — chapitre « %s » sans fiche de cours (chapters/%s/lesson.md)', $track->id, $chapter->title, $chapter->id));
            }
        }

        // Clés dépréciées : acceptées, mais à retirer avant qu'une version majeure ne les refuse.
        $deprecations = $this->content->deprecations();
        foreach ($exercises as $exercise) {
            $key = ($exercise->trackId ?? ContentRepository::PRACTICE).'/'.$exercise->id;
            if (isset($deprecations[$key])) {
                $io->writeln(sprintf(' <fg=yellow>!</> %s — %s', $key, $deprecations[$key]));
            }
        }

        $io->newLine();
        if ($failures) {
            $io->error(sprintf('%d exercice(s) sur %d à corriger.', $failures, \count($exercises)));

            return Command::FAILURE;
        }
        $io->success(sprintf('%d exercice(s) conformes.', \count($exercises)));

        return Command::SUCCESS;
    }
}
