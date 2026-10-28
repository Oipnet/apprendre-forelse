<?php

namespace App\Api;

use App\Content\ChapterOutline;
use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Content\Practice;
use App\Content\PracticeTrack;
use App\Content\Track;
use App\Content\TrackVisibility;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Où mène un exercice, pour le playground : le fil d'Ariane du chapitre, l'exercice précédent et le suivant, la
 * fiche que sa réussite débloque, le parcours conseillé ensuite. Une partie d'ExercisePayload
 * (playground/src/app/types.ts), testable sans le reste de l'exercice.
 */
final readonly class ExerciseNavigation
{
    public function __construct(
        private ContentRepository $content,
        private TrackVisibility $visibility,
        private PracticeTrack $practiceTracks,
        private UrlGeneratorInterface $urls,
        private ExerciseUrls $exerciseUrls,
    ) {
    }

    /**
     * @return array{chapter: array<string, mixed>|null, previous: array<string, string>|null, next: array<string, string>|null, lesson: array<string, string>|null, nextTrack: array<string, string>|null}
     */
    public function of(Exercise $exercise): array
    {
        $next = $this->content->next($exercise);
        $previous = $this->content->previous($exercise);
        // Dernier exercice du parcours : on conseille le parcours suivant, s'il y en a un.
        $track = null === $exercise->trackId ? null : $this->content->findTrack($exercise->trackId, $exercise->locale);
        $nextTrack = null === $next && null !== $track ? $this->visibility->nextTrack($track) : null;
        // Dernier exercice d'un chapitre doté d'une fiche de cours : la réussite la débloque.
        $inChapter = null === $track ? null : $this->content->chapterOf($exercise);
        $lesson = $inChapter?->hasLesson() && $this->content->closesChapter($exercise) ? $inChapter : null;

        return [
            // Où l'on est dans le parcours : le fil d'Ariane du playground (chapitre, « exercice n sur N »).
            'chapter' => null !== $track && null !== $inChapter ? [
                'title' => $inChapter->title,
                'number' => ChapterOutline::numberOf($track, $inChapter),
                'position' => (int) array_search($exercise->id, $inChapter->exerciseIds, true) + 1,
                'total' => \count($inChapter->exerciseIds),
                'url' => $this->urls->generate('app_chapter_summary', ['trackId' => $track->id, 'chapterId' => $inChapter->id]),
            ] : null,
            'previous' => $previous ? $this->exercise($previous) : null,
            'next' => $next ? $this->exercise($next) : null,
            'lesson' => $lesson ? [
                'title' => $lesson->title,
                'url' => $this->urls->generate('app_chapter', ['trackId' => $exercise->trackId, 'chapterId' => $lesson->id]),
            ] : null,
            'nextTrack' => $nextTrack ? $this->track($nextTrack) : null,
        ];
    }

    /**
     * Après la réussite, la Pratique propose le parcours du même framework, s'il y en a un de visible. Les parcours
     * sont en français : une traduction n'y renvoie pas.
     *
     * @return array<string, string>|null
     */
    public function practiceTrack(Practice $practice, string $locale): ?array
    {
        $track = Exercise::LOCALE === $locale ? $this->practiceTracks->of($practice) : null;

        return $track ? $this->track($track) : null;
    }

    /** @return array<string, string> */
    private function exercise(Exercise $exercise): array
    {
        return [
            'id' => $exercise->id,
            'title' => $exercise->title,
            'url' => $this->exerciseUrls->generate('app_exercise', $exercise),
        ];
    }

    /** @return array<string, string> */
    private function track(Track $track): array
    {
        return [
            'id' => $track->id,
            'title' => $track->title,
            'description' => $track->description,
            'url' => $this->urls->generate('app_track', ['trackId' => $track->id]),
        ];
    }
}
