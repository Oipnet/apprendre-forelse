<?php

namespace App\Content;

/**
 * Navigation dans le contenu chargé : sélection d'exercices, chapitres, exercice suivant, durées.
 */
final class ContentIndex
{
    /** @var array<string, array<string, Chapter>> chapitre de chaque exercice, par langue puis « parcours/exercice » (voir chapterOf()) */
    private array $chapterIndex = [];

    public function __construct(private readonly LoadedContent $content)
    {
    }

    public function findExercise(string $trackId, string $exerciseId, string $locale = Exercise::LOCALE): ?Exercise
    {
        return $this->exercisesOf($trackId, $locale)[$exerciseId] ?? null;
    }

    /** @return array<string, Track> les parcours de cette langue, dans l'ordre des parcours français */
    public function tracks(string $locale = Exercise::LOCALE): array
    {
        return Exercise::LOCALE === $locale ? $this->content->tracks : $this->content->trackTranslations[$locale] ?? [];
    }

    /** @return array<string, Exercise> les exercices d'un parcours dans cette langue, dans l'ordre */
    private function exercisesOf(string $trackId, string $locale): array
    {
        return Exercise::LOCALE === $locale ? $this->content->exercises[$trackId] ?? [] : $this->content->exerciseTranslations[$locale][$trackId] ?? [];
    }

    /**
     * Les exercices désignés par un pack, un parcours, « parcours/exercice », « pratique » ou « pratique/exercice »
     * (tous si null), éventuellement limités à un chapitre (utile pour répartir une vérification en parallèle).
     * Un pack comprend ses exercices de Pratique ; un chapitre les exclut.
     *
     * @return list<Exercise>
     */
    public function select(?string $target, ?string $chapterId = null): array
    {
        $exercises = [];
        foreach ($this->content->tracks as $track) {
            foreach ($track->chapters as $chapter) {
                if (null !== $chapterId && $chapter->id !== $chapterId) {
                    continue;
                }
                foreach ($chapter->exerciseIds as $exerciseId) {
                    $exercise = $this->findExercise($track->id, $exerciseId);
                    if (null !== $exercise && (null === $target || \in_array($target, [$track->packId, $track->id, $track->id.'/'.$exercise->id], true))) {
                        $exercises[] = $exercise;
                        // Chaque traduction se vérifie à part, même avant celle du parcours.
                        foreach ($this->content->exerciseTranslations as $translations) {
                            if (null !== ($translation = $translations[$track->id][$exerciseId] ?? null)) {
                                $exercises[] = $translation;
                            }
                        }
                    }
                }
            }
        }
        if (null !== $chapterId) {
            return $exercises;
        }
        foreach ($this->content->practices as $practice) {
            if (null === $target || \in_array($target, [$practice->packId, ContentRepository::PRACTICE, ContentRepository::PRACTICE.'/'.$practice->exercise->id], true)) {
                $exercises[] = $practice->exercise;
                // Chaque traduction se vérifie à part : ses fichiers traduits peuvent casser l'exercice.
                foreach ($this->content->practiceTranslations as $translations) {
                    if (null !== ($translation = $translations[$practice->exercise->id] ?? null)) {
                        $exercises[] = $translation->exercise;
                    }
                }
            }
        }

        return $exercises;
    }

    /**
     * Durée estimée d'un parcours, ou d'un de ses chapitres, en minutes : la somme de celles de ses exercices.
     * Null dès qu'un exercice n'en a pas : une somme partielle promettrait moins de temps qu'il n'en faut.
     */
    public function durationOf(Track $track, ?Chapter $chapter = null): ?int
    {
        $total = 0;
        foreach ($chapter->exerciseIds ?? $track->exerciseIds() as $exerciseId) {
            $duration = $this->findExercise($track->id, $exerciseId, $track->locale)?->duration;
            if (null === $duration) {
                return null;
            }
            $total += $duration;
        }

        return $total > 0 ? $total : null;
    }

    public function findChapter(Track $track, string $chapterId): ?Chapter
    {
        foreach ($track->chapters as $chapter) {
            if ($chapter->id === $chapterId) {
                return $chapter;
            }
        }

        return null;
    }

    /**
     * Le chapitre d'un exercice. Appelé pour chaque exercice affiché, et en boucle (import de la progression d'un
     * invité) : un index, construit une fois depuis les parcours chargés, plutôt qu'un parcours de tout le contenu.
     */
    public function chapterOf(Exercise $exercise): ?Chapter
    {
        if (null === $exercise->trackId) {
            return null;
        }
        if (!isset($this->chapterIndex[$exercise->locale])) {
            $this->chapterIndex[$exercise->locale] = [];
            foreach ($this->tracks($exercise->locale) as $track) {
                foreach ($track->chapters as $chapter) {
                    foreach ($chapter->exerciseIds as $exerciseId) {
                        // Le premier chapitre qui le cite, comme chaptersOf().
                        $this->chapterIndex[$exercise->locale][$track->id.'/'.$exerciseId] ??= $chapter;
                    }
                }
            }
        }

        return $this->chapterIndex[$exercise->locale][$exercise->trackId.'/'.$exercise->id] ?? null;
    }

    /** L'exercice qui clôt un chapitre est le dernier de sa liste. */
    public function closesChapter(Exercise $exercise): bool
    {
        $chapter = $this->chapterOf($exercise);

        return null !== $chapter && $exercise->id === ($chapter->exerciseIds[\count($chapter->exerciseIds) - 1] ?? null);
    }

    /**
     * Les chapitres auxquels appartiennent ces exercices, dans l'ordre des parcours, dans la langue des exercices
     * (le français s'ils en mêlent plusieurs).
     *
     * @param list<Exercise> $exercises
     *
     * @return list<array{Track, Chapter}>
     */
    public function chaptersOf(array $exercises): array
    {
        $exercises = array_filter($exercises, static fn (Exercise $e) => null !== $e->trackId);
        $locales = array_unique(array_map(static fn (Exercise $e) => $e->locale, $exercises));
        $ids = array_map(static fn (Exercise $e) => $e->trackId.'/'.$e->id, $exercises);
        $chapters = [];
        foreach ($this->tracks(1 === \count($locales) ? (string) reset($locales) : Exercise::LOCALE) as $track) {
            foreach ($track->chapters as $chapter) {
                foreach ($chapter->exerciseIds as $exerciseId) {
                    if (\in_array($track->id.'/'.$exerciseId, $ids, true)) {
                        $chapters[] = [$track, $chapter];
                        break;
                    }
                }
            }
        }

        return $chapters;
    }

    /** @return list<string> les identifiants de chapitres, tous parcours confondus */
    public function chapterIds(): array
    {
        $ids = [];
        foreach ($this->content->tracks as $track) {
            foreach ($track->chapters as $chapter) {
                $ids[] = $chapter->id;
            }
        }

        return $ids;
    }

    public function next(Exercise $exercise): ?Exercise
    {
        return $this->neighbour($exercise, 1);
    }

    public function previous(Exercise $exercise): ?Exercise
    {
        return $this->neighbour($exercise, -1);
    }

    /** L'exercice à `offset` places dans l'ordre du parcours ; une Pratique n'a pas de voisin. */
    private function neighbour(Exercise $exercise, int $offset): ?Exercise
    {
        if (null === $exercise->trackId) {
            return null;
        }
        $ids = array_keys($this->exercisesOf($exercise->trackId, $exercise->locale));
        $position = array_search($exercise->id, $ids, true);

        return false === $position ? null : $this->findExercise($exercise->trackId, (string) ($ids[$position + $offset] ?? ''), $exercise->locale);
    }
}
