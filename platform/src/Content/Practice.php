<?php

namespace App\Content;

/**
 * Un exercice de Pratique : à part des parcours, sans chapitre ni XP, pour se servir d'une fonctionnalité d'un
 * framework (souvent une nouveauté d'une version, parfois un point précis qui ne l'est pas).
 *
 * Il vit dans `<pack>/practice/<id>/`, avec le format habituel d'un exercice et quelques clés en plus
 * (published, summary, version, pull_request, visibility).
 */
final readonly class Practice
{
    public function __construct(
        public Exercise $exercise,
        public string $packId,
        /** Framework de l'environnement (symfony, laravel, docker). */
        public string $framework,
        public \DateTimeImmutable $published,
        public string $summary,
        /** Version du framework qui apporte la fonctionnalité (« 8.1 »), si c'est une nouveauté. */
        public ?string $version = null,
        public ?string $pullRequest = null,
        /** « public », ou « admin » : en préparation, visible des seuls administrateurs. */
        public string $visibility = Track::VISIBILITY_PUBLIC,
    ) {
    }

    public function isRestricted(): bool
    {
        return Track::VISIBILITY_ADMIN === $this->visibility;
    }

    /** Daté d'un jour à venir : publié automatiquement ce jour-là, visible des seuls administrateurs d'ici là. */
    public function isScheduled(?\DateTimeImmutable $today = null): bool
    {
        return $this->published->format('Y-m-d') > ($today ?? new \DateTimeImmutable('today'))->format('Y-m-d');
    }
}
