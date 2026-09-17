<?php

namespace App\Command;

use App\Entity\Feedback;
use App\Export\CsvExport;
use App\Repository\FeedbackRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'beta:feedback',
    description: 'Exporte en CSV les retours laissés par les apprenants sur les exercices (bouton « Un avis ? »).',
)]
final class BetaFeedbackCommand
{
    private const array HEADER = ['date', 'cohorte', 'pseudo', 'parcours', 'exercice', 'type', 'indices', 'reussi', 'message'];

    public function __construct(private readonly FeedbackRepository $feedbacks)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        OutputInterface $output,
        #[Option('Ne garder que les retours des comptes inscrits avec ce code d\'invitation')] ?string $cohort = null,
        #[Option('Fichier CSV à écrire (sortie standard sinon)')] ?string $output_file = null,
    ): int {
        $rows = array_map(static fn (Feedback $f) => [
            $f->getCreatedAt()->format('Y-m-d H:i'),
            $f->getUser()->getCohort()?->getCode(),
            $f->getUser()->getDisplayName(),
            $f->getTrackId() ?? 'pratique',
            $f->getExerciseId(),
            $f->getKind()->label(),
            $f->getHintsUsed(),
            $f->isCompleted(),
            $f->getMessage(),
        ], $this->feedbacks->findByCohort($cohort));

        $count = CsvExport::write(self::HEADER, $rows, $output_file, $output);
        if (null !== $output_file) {
            $io->success(sprintf('%d retour(s) écrit(s) dans %s.', $count, $output_file));
        }

        return Command::SUCCESS;
    }
}
