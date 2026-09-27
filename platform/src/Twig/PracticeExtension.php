<?php

namespace App\Twig;

use App\Content\Practice;
use Psr\Clock\ClockInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** `{% if practice_scheduled(practice) %}` : un exercice de Pratique daté d'un jour à venir, selon l'horloge. */
final class PracticeExtension extends AbstractExtension
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('practice_scheduled', fn (Practice $practice): bool => $practice->isScheduled($this->clock->now()))];
    }
}
