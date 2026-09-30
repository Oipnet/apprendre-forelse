<?php

namespace App\Content;

/**
 * Un article du blog : `<pack>/articles/<slug>.md`, un en-tête YAML (titre, description, dates) puis du Markdown.
 *
 * Le blog n'est pas là pour annoncer : il raconte ce qu'aucune page d'exercice ne dit — comment le moteur fait
 * tourner un framework dans le navigateur, pourquoi un exercice est construit ainsi. Ce sont ces pages-là que
 * d'autres sites citent, et un lien vers le site aide chacune de ses pages à être explorée.
 */
final readonly class Article
{
    public function __construct(
        /** Le nom du fichier, sans « .md » : l'identifiant de l'article dans son adresse. */
        public string $slug,
        public string $packId,
        public string $title,
        /** Une ou deux phrases, sans balise : la meta description et le chapeau de la liste. */
        public string $description,
        public \DateTimeImmutable $published,
        public string $markdown,
        public string $file,
        /** Dernière révision qui change le fond, déclarée par l'auteur (clé `updated`) : la date d'un fichier ne dit pas si le texte a changé. */
        public ?\DateTimeImmutable $updated = null,
        /** « public », ou « admin » : en préparation, visible des seuls administrateurs. */
        public string $visibility = Track::VISIBILITY_PUBLIC,
    ) {
    }

    public function isRestricted(): bool
    {
        return Track::VISIBILITY_ADMIN === $this->visibility;
    }

    /** Daté d'un jour à venir : publié automatiquement ce jour-là, visible des seuls administrateurs d'ici là. */
    public function isScheduled(\DateTimeImmutable $today): bool
    {
        return $this->published->format('Y-m-d') > $today->format('Y-m-d');
    }

    /** La date de la dernière version du texte, au format AAAA-MM-JJ (sitemap, données structurées). */
    public function modified(): string
    {
        return ($this->updated ?? $this->published)->format('Y-m-d');
    }

    /** Minutes de lecture, à 200 mots par minute, code compris : au moins une. */
    public function readingMinutes(): int
    {
        return max(1, (int) round(str_word_count(strip_tags($this->markdown), 0, 'àâäçéèêëîïôöùûüÿœæÀÂÄÇÉÈÊËÎÏÔÖÙÛÜŸŒÆ\'’') / 200));
    }
}
