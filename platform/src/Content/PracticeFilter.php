<?php

namespace App\Content;

/**
 * Les filtres de la liste de Pratique, lus dans l'URL (#[MapQueryString]) : un framework, des notions cumulables, les
 * nouveautés seules, une recherche, un tri. Normalisés ici : le formulaire envoie un framework vide pour « Tous », et un
 * tri inconnu retombe sur le plus récent.
 */
final readonly class PracticeFilter
{
    public const string SORT_RECENT = 'recent';
    public const string SORT_TITLE = 'titre';

    public ?string $framework;
    /** @var list<string> */
    public array $notions;
    public string $recherche;
    public string $tri;

    /** @param array<mixed>|string $notions une seule notion peut arriver sans crochets (?notions=Validator) */
    public function __construct(
        ?string $framework = null,
        array|string $notions = [],
        public bool $nouveautes = false,
        ?string $recherche = null,
        ?string $tri = null,
    ) {
        $this->framework = '' !== $framework ? $framework : null;
        $this->notions = array_values(array_unique(array_filter((array) $notions, \is_string(...))));
        $this->recherche = trim($recherche ?? '');
        $this->tri = self::SORT_TITLE === $tri ? self::SORT_TITLE : self::SORT_RECENT;
    }

    /** @param list<string> $notions */
    public function withNotions(array $notions): self
    {
        return new self($this->framework, $notions, $this->nouveautes, $this->recherche, $this->tri);
    }

    /** @return array{framework: ?string, notions: list<string>, nouveautes: bool, recherche: string, tri: string} */
    public function toArray(): array
    {
        return ['framework' => $this->framework, 'notions' => $this->notions, 'nouveautes' => $this->nouveautes, 'recherche' => $this->recherche, 'tri' => $this->tri];
    }
}
