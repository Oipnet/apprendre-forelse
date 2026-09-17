<?php

namespace App\Command;

use App\Content\ContentRepository;
use App\Entity\ExerciseProgress;
use App\Entity\User;
use App\Export\CsvExport;
use App\Repository\ExerciseProgressRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'beta:progress',
    description: 'Exporte en CSV la progression des apprenants, exercice par exercice (pour un enseignant, une cohorte…).',
)]
final class BetaProgressCommand
{
    private const array HEADER = ['cohorte', 'pseudo', 'email', 'parcours', 'chapitre', 'position', 'exercice', 'titre', 'statut', 'indices', 'xp', 'commence_le', 'termine_le', 'duree_min'];

    public function __construct(
        private readonly UserRepository $users,
        private readonly ExerciseProgressRepository $progress,
        private readonly ContentRepository $content,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        OutputInterface $output,
        #[Option('Ne garder que les comptes inscrits avec ce code d\'invitation')] ?string $cohort = null,
        #[Option('Fichier CSV à écrire (sortie standard sinon)')] ?string $output_file = null,
    ): int {
        $users = $this->users->findByCohort($cohort);
        if (!$users) {
            $io->error(null === $cohort ? 'Aucun compte.' : sprintf('Aucun compte dans la cohorte « %s ».', $cohort));

            return Command::FAILURE;
        }

        $count = CsvExport::write(self::HEADER, $this->rows($users), $output_file, $output);
        if (null !== $output_file) {
            $io->success(sprintf('%d ligne(s) écrite(s) dans %s.', $count, $output_file));
        }

        return Command::SUCCESS;
    }

    /**
     * Une ligne par apprenant et par exercice, y compris ceux jamais commencés : les abandons se voient.
     *
     * @param list<User> $users
     *
     * @return iterable<list<scalar|null>>
     */
    private function rows(array $users): iterable
    {
        foreach ($users as $user) {
            foreach ($this->content->tracks() as $track) {
                $byExercise = $this->progress->findByTrack($user, $track->id);
                $position = 0;
                foreach ($track->chapters as $chapter) {
                    foreach ($chapter->exerciseIds as $exerciseId) {
                        ++$position;
                        $exercise = $this->content->findExercise($track->id, $exerciseId);
                        $progress = $byExercise[$exerciseId] ?? null;
                        yield [
                            $user->getCohort()?->getCode(),
                            $user->getDisplayName(),
                            $user->getEmail(),
                            $track->id,
                            $chapter->title,
                            $position,
                            $exerciseId,
                            $exercise?->title,
                            $progress?->getStatus()->value ?? 'todo',
                            $progress?->getHintsUsed() ?? 0,
                            $progress?->getXpEarned() ?? 0,
                            $progress?->getStartedAt()->format('Y-m-d H:i'),
                            $progress?->getCompletedAt()?->format('Y-m-d H:i'),
                            $progress ? self::durationMinutes($progress) : null,
                        ];
                    }
                }
            }
        }
    }

    /** Durée approximative : du premier brouillon sauvegardé à la réussite. */
    private static function durationMinutes(ExerciseProgress $progress): ?int
    {
        $completedAt = $progress->getCompletedAt();

        return null === $completedAt ? null : max(0, (int) round(($completedAt->getTimestamp() - $progress->getStartedAt()->getTimestamp()) / 60));
    }
}
