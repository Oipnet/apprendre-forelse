<?php

namespace App\Command;

use App\Content\ContentRepository;
use App\Leaderboard\TrackBonuses;
use App\Repository\ExerciseProgressRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Attribue le bonus des parcours terminés avant son arrivée : à lancer une fois après la mise à jour qui l'introduit.
 * Sans effet sur un bonus déjà attribué : on peut la relancer.
 */
#[AsCommand(
    name: 'app:classement:bonus-parcours',
    description: 'Attribue le bonus des parcours déjà terminés (une fois, après la mise à jour qui l\'introduit).',
)]
final readonly class TrackBonusCommand
{
    public function __construct(
        private ContentRepository $content,
        private ExerciseProgressRepository $progress,
        private TrackBonuses $bonuses,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $awarded = 0;
        foreach ($this->content->tracks() as $track) {
            foreach ($this->progress->usersWithCompletionsIn($track->id) as $user) {
                $xp = $this->bonuses->awardIfComplete($user, $track);
                if ($xp > 0) {
                    ++$awarded;
                    $io->writeln(sprintf('%s : %s, +%d XP', $user->getDisplayName(), $track->title, $xp));
                }
            }
            $this->entityManager->flush();
        }
        $io->success(0 === $awarded ? 'Aucun bonus à attribuer.' : sprintf('%d bonus attribué(s).', $awarded));

        return Command::SUCCESS;
    }
}
