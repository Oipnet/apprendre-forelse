<?php

namespace App\Content;

/**
 * Le parcours à proposer après un exercice de Pratique : le premier parcours visible du même framework.
 * La Pratique est la porte d'entrée de la plateforme, le parcours ce qui la suit. Aucun parcours visible
 * de ce framework (un parcours encore en préparation, par exemple) : rien n'est proposé.
 */
final readonly class PracticeTrack
{
    public function __construct(
        private TrackVisibility $tracks,
        private EnvironmentRegistry $environments,
    ) {
    }

    public function of(Practice $practice): ?Track
    {
        foreach ($this->tracks->tracks() as $track) {
            if ($this->environments->frameworkOf($track->environment)->id === $practice->framework) {
                return $track;
            }
        }

        return null;
    }
}
