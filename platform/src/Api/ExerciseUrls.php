<?php

namespace App\Api;

use App\Content\Exercise;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Les URL d'un exercice, qu'il appartienne à un parcours (/parcours/{trackId}/{exerciseId}…) ou à la Pratique :
 * chaque route de la Pratique porte le nom de sa jumelle suivi de « _pratique », sans identifiant de parcours.
 */
final readonly class ExerciseUrls
{
    public const string PRACTICE_SUFFIX = '_pratique';

    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function generate(string $route, Exercise $exercise): string
    {
        return null === $exercise->trackId
            ? $this->urls->generate($route.self::PRACTICE_SUFFIX, ['exerciseId' => $exercise->id])
            : $this->urls->generate($route, ['trackId' => $exercise->trackId, 'exerciseId' => $exercise->id]);
    }
}
