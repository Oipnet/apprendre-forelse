<?php

namespace App\Search;

/**
 * Une page du site telle que la recherche la connaît : ce qu'on y cherche (titre, mots-clés, résumé, texte) et ce
 * qu'on affiche du résultat (type, titre, étiquette, adresse). Le texte est du texte brut, sans Markdown ni HTML.
 */
final readonly class SearchDocument
{
    public const string PRACTICE = 'pratique';
    public const string ARTICLE = 'article';
    public const string CONCEPT = 'notion';
    public const string TRACK = 'parcours';
    public const string CHAPTER = 'chapitre';
    public const string EXERCISE = 'exercice';

    /** Les types, dans l'ordre des onglets de la page de résultats. */
    public const array TYPES = [self::PRACTICE, self::ARTICLE, self::CONCEPT, self::TRACK, self::CHAPTER, self::EXERCISE];

    /**
     * Départage deux résultats aussi pertinents l'un que l'autre : une notion ou un parcours, pages d'entrée, avant
     * l'exercice d'un chapitre.
     */
    private const array WEIGHTS = [
        self::CONCEPT => 6,
        self::TRACK => 6,
        self::PRACTICE => 5,
        self::ARTICLE => 5,
        self::CHAPTER => 4,
        self::EXERCISE => 3,
    ];

    /**
     * @param string       $key      identifiant de la page dans son type (slug, ou parcours/exercice)
     * @param list<string> $keywords notions, sujets : cherchés juste après le titre
     */
    public function __construct(
        public string $type,
        public string $key,
        public string $title,
        public string $url,
        public string $label = '',
        public string $summary = '',
        public string $text = '',
        public array $keywords = [],
    ) {
    }

    /** L'identifiant du document dans Meilisearch : lettres, chiffres, tirets et soulignés seulement. */
    public function id(): string
    {
        return self::idOf($this->type, $this->key);
    }

    public static function idOf(string $type, string $key): string
    {
        $id = $type.'-'.preg_replace('/[^A-Za-z0-9_-]/', '_', $key);

        return \strlen($id) <= 200 ? $id : $type.'-'.hash('xxh128', $key);
    }

    /** @return array<string, mixed> le document envoyé à Meilisearch */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'type' => $this->type,
            'title' => $this->title,
            'url' => $this->url,
            'label' => $this->label,
            'summary' => $this->summary,
            'text' => $this->text,
            'keywords' => $this->keywords,
            'weight' => self::WEIGHTS[$this->type] ?? 0,
        ];
    }
}
