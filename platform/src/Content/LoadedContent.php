<?php

namespace App\Content;

/**
 * Le contenu des packs, tel que PackReader l'a lu et validé : ce que PackLoader met en cache, et que ContentRepository
 * sert. Une clé ajoutée au tableau change son format : PackLoader::CACHE_FORMAT écarte alors les caches déjà écrits.
 */
final readonly class LoadedContent
{
    /**
     * @param array<string, Pack>                                                 $packs
     * @param array<string, Track>                                                $tracks
     * @param array<string, array<string, Exercise>>                              $exercises     exercices par parcours, dans l'ordre
     * @param array<string, Practice>                                             $practices     du plus récent au plus ancien
     * @param array<string, string>                                               $deprecations  clés dépréciées, par « parcours/exercice »
     * @param array<string, array{markdown: string, file: string, packId: string}> $versionIntros intros de pages de nouveautés, par version
     * @param array<string, Article>                                              $articles      articles du blog, du plus récent au plus ancien
     * @param array<string, string>                                               $watched       empreinte de chaque fichier ou dossier lu
     */
    public function __construct(
        public array $packs,
        public array $tracks,
        public array $exercises,
        public array $practices,
        public array $deprecations,
        public array $versionIntros,
        public array $articles,
        public array $watched,
    ) {
    }

    /**
     * @param array{packs: array<string, Pack>, tracks: array<string, Track>, exercises: array<string, array<string, Exercise>>, practices: array<string, Practice>, deprecations: array<string, string>, versionIntros: array<string, array{markdown: string, file: string, packId: string}>, articles: array<string, Article>, watched: array<string, string>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['packs'], $data['tracks'], $data['exercises'], $data['practices'], $data['deprecations'], $data['versionIntros'], $data['articles'], $data['watched']);
    }

    /**
     * @return array{packs: array<string, Pack>, tracks: array<string, Track>, exercises: array<string, array<string, Exercise>>, practices: array<string, Practice>, deprecations: array<string, string>, versionIntros: array<string, array{markdown: string, file: string, packId: string}>, articles: array<string, Article>, watched: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'packs' => $this->packs, 'tracks' => $this->tracks, 'exercises' => $this->exercises, 'practices' => $this->practices,
            'deprecations' => $this->deprecations, 'versionIntros' => $this->versionIntros, 'articles' => $this->articles, 'watched' => $this->watched,
        ];
    }
}
