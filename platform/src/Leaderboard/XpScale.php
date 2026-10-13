<?php

namespace App\Leaderboard;

use App\Content\ContentRepository;
use App\Content\TrackVisibility;
use App\Service\XpCalculator;

/** Le barème de l'XP tel que le classement l'affiche : les valeurs réelles du moteur et des exercices publiés. */
final readonly class XpScale
{
    public function __construct(
        private ContentRepository $content,
        private TrackVisibility $tracks,
    ) {
    }

    /**
     * Les fourchettes d'XP d'un exercice de parcours et de Pratique (null sans exercice), et les pourcentages.
     *
     * @return array{track: array{int, int}|null, practice: array{int, int}|null, hintPenalty: int, floor: int, trackBonus: int}
     */
    public function rules(): array
    {
        $trackXp = [];
        foreach ($this->tracks->tracks() as $track) {
            foreach ($this->content->exercisesOf($track) as $exercise) {
                $trackXp[] = $exercise->xp;
            }
        }
        $practiceXp = [];
        foreach ($this->content->practices() as $practice) {
            if (!$practice->isRestricted()) {
                $practiceXp[] = $practice->exercise->xp;
            }
        }

        return [
            'track' => [] === $trackXp ? null : [min($trackXp), max($trackXp)],
            'practice' => [] === $practiceXp ? null : [min($practiceXp), max($practiceXp)],
            'hintPenalty' => XpCalculator::HINT_PENALTY,
            'floor' => XpCalculator::FLOOR,
            'trackBonus' => TrackBonuses::RATE,
        ];
    }
}
