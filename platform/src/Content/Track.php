<?php

namespace App\Content;

final readonly class Track
{
    /**
     * @param list<Chapter> $chapters
     * @param list<string>  $downloads fichiers de DOWNLOADS_DIR proposés au téléchargement (clé « downloads »)
     */
    public function __construct(
        public string $id,
        public string $packId,
        public string $title,
        public string $description,
        public string $environment,
        public array $chapters,
        public string $directory,
        /** Parcours conseillé ensuite (clé « next » de track.yaml), s'il est installé. */
        public ?string $next = null,
        /** « public », ou « admin » : en préparation, visible des seuls administrateurs et des cohortes qui l'ont choisi (voir TrackVisibility). */
        public string $visibility = self::VISIBILITY_PUBLIC,
        /** Rang d'affichage parmi les parcours (clé « order » de track.yaml) : le plus petit d'abord, sans rang à la fin. */
        public ?int $order = null,
        /** Fichiers de DOWNLOADS_DIR réservés à ce parcours (clé « downloads » de track.yaml), voir DownloadPolicy. */
        public array $downloads = [],
        /** Image de partage (clé « image » de track.yaml, relative au dossier du parcours) : chemin absolu, déjà validé. */
        public ?string $image = null,
    ) {
    }

    public const string VISIBILITY_PUBLIC = 'public';
    public const string VISIBILITY_ADMIN = 'admin';
    public const array VISIBILITIES = [self::VISIBILITY_PUBLIC, self::VISIBILITY_ADMIN];

    /** Réservé aux administrateurs (et aux cohortes qui l'ont choisi) : absent des listes et introuvable pour les autres. */
    public function isRestricted(): bool
    {
        return self::VISIBILITY_ADMIN === $this->visibility;
    }

    /** Le premier chapitre d'un parcours public : la porte d'entrée, gratuite, une fois le compte créé. */
    public function isFreeChapter(Chapter $chapter): bool
    {
        return !$this->isRestricted() && ($this->chapters[0] ?? null)?->id === $chapter->id;
    }

    /** @return list<string> tous les exercices, dans l'ordre du parcours */
    public function exerciseIds(): array
    {
        return array_merge(...array_map(static fn (Chapter $c) => $c->exerciseIds, $this->chapters));
    }

    /** @return list<Chapter> les chapitres dotés d'une fiche de cours, dans l'ordre du parcours */
    public function lessonChapters(): array
    {
        return array_values(array_filter($this->chapters, static fn (Chapter $c) => $c->hasLesson()));
    }
}
