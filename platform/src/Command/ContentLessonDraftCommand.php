<?php

namespace App\Command;

use App\Content\Author\LessonDrafter;
use App\Content\ContentException;
use App\Content\ContentRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'content:lesson-draft',
    description: 'Prépare la fiche de cours d\'un chapitre (chapters/<chapitre>/lesson.md) : squelette à compléter, ou brouillon rédigé par le modèle.',
)]
final class ContentLessonDraftCommand
{
    public function __construct(
        private readonly ContentRepository $content,
        private readonly LessonDrafter $drafter,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Le chapitre, sous la forme parcours/chapitre')] string $cible,
        #[Option('Demander un brouillon rédigé au modèle (ANTHROPIC_API_KEY) plutôt qu\'un squelette')] bool $ia = false,
        #[Option('Remplacer une fiche existante')] bool $force = false,
    ): int {
        [$trackId, $chapterId] = array_pad(explode('/', $cible, 2), 2, '');
        $track = $this->content->findTrack($trackId);
        $chapter = $track ? $this->content->findChapter($track, $chapterId) : null;
        if (!$track || !$chapter) {
            $io->error(sprintf('Chapitre « %s » introuvable. Attendu : parcours/chapitre, par exemple %s.', $cible, $this->exemple()));

            return Command::FAILURE;
        }

        try {
            $markdown = $ia ? $this->drafter->brouillon($track, $chapter) : $this->drafter->squelette($track, $chapter);
            $chemin = $this->drafter->ecrire($track, $chapter, $markdown, $force);
        } catch (ContentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('%s écrit : %s', $ia ? 'Brouillon' : 'Squelette', $chemin));
        $io->text($ia
            ? 'Relisez-le comme un brouillon : le modèle peut se tromper sur une API ou un comportement.'
            : 'Les commentaires <!-- … --> guident la rédaction et ne sont pas rendus. Avec --ia, le modèle rédige un brouillon complet.');

        return Command::SUCCESS;
    }

    private function exemple(): string
    {
        foreach ($this->content->tracks() as $track) {
            foreach ($track->chapters as $chapter) {
                return $track->id.'/'.$chapter->id;
            }
        }

        return 'mon-parcours/mon-chapitre';
    }
}
