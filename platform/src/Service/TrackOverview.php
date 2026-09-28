<?php

namespace App\Service;

use App\Content\ContentRepository;
use App\Content\Track;
use App\Content\TrackVisibility;
use App\Entity\User;
use App\Payment\TrackOfferFactory;
use App\Repository\ExerciseProgressRepository;
use App\Security\TrackAccessChecker;

/**
 * La page d'un parcours : ses chapitres et leurs exercices avec l'état de chacun, l'XP, l'exercice où reprendre,
 * les fiches de cours et l'offre d'achat.
 */
final readonly class TrackOverview
{
    public function __construct(
        private ContentRepository $content,
        private TrackVisibility $visibility,
        private ExerciseProgressRepository $progressRepository,
        private TrackAccessChecker $access,
        private TrackOfferFactory $offers,
    ) {
    }

    /**
     * @param bool $reviewer un auteur : les fiches de cours et le livret lui sont ouverts sans avoir fini
     *
     * @return array<string, mixed>
     */
    public function of(Track $track, ?User $user, bool $reviewer): array
    {
        $progress = TrackProgress::of($track, null !== $user ? $this->progressRepository->findByTrack($user, $track->id) : []);

        $chapters = [];
        $xpTotal = 0;
        // « Vous en êtes là » : le premier exercice pas encore réussi, dans l'ordre du parcours.
        $nextId = $progress->next();
        $current = null;
        foreach ($track->chapters as $number => $chapter) {
            $items = [];
            $chapterXp = 0;
            foreach ($chapter->exerciseIds as $position => $exerciseId) {
                $exercise = $this->content->findExercise($track->id, $exerciseId);
                $state = $progress->stateOf($exerciseId);
                $chapterXp += $exercise->xp ?? 0;
                $items[] = [
                    'exercise' => $exercise,
                    'state' => $state,
                    'xpEarned' => $progress->xpEarnedOn($exerciseId),
                    // Le dernier exercice d'un chapitre titré « Boss » : mis en valeur.
                    'boss' => null !== $exercise && $exercise->isBoss(),
                ];
                if ($exerciseId === $nextId && null !== $exercise) {
                    $current = ['exercise' => $exercise, 'chapter' => $number + 1, 'position' => $position + 1, 'started' => TrackProgress::TODO !== $state];
                }
            }
            $xpTotal += $chapterXp;
            $chapters[] = [
                'chapter' => $chapter,
                'number' => $number + 1,
                'items' => $items,
                'xp' => $chapterXp,
                // La fiche de cours, si le chapitre en a une : lisible ou encore verrouillée.
                'lesson' => $chapter->hasLesson() ? $progress->lesson($chapter, $reviewer) : null,
                // Premier chapitre libre ; les autres demandent l'accès au parcours (la progression s'affiche quand même).
                'free' => $track->isFreeChapter($chapter),
                'locked' => !$this->access->canAccess($user, $track, $chapter),
            ];
        }

        return [
            'track' => $track,
            'pack' => $this->content->packs()[$track->packId],
            'chapters' => $chapters,
            'completed' => $progress->completedCount(),
            'total' => $progress->total(),
            'xpTotal' => $xpTotal,
            'xpEarned' => $progress->xpEarned(),
            'current' => $current,
            'started' => $progress->hasStarted(),
            'nextTrack' => $this->visibility->nextTrack($track),
            'offer' => $this->offers->create($track, $user),
            // Le livret regroupe les fiches : proposé quand tout le parcours est réussi (ou à un auteur).
            'booklet' => $track->lessonChapters() && ($reviewer || $progress->isComplete()),
        ];
    }
}
