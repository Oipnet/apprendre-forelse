<?php

namespace App\Content;

/**
 * Le besoin posé par le personnage, en une phrase : le premier vrai paragraphe de la consigne, en texte brut.
 * Sert de résumé partout où un exercice s'annonce sans se raconter en entier (description SEO, sommaire de
 * chapitre), et pour tout autre texte Markdown écrit dans un pack (voir firstParagraph).
 */
final readonly class ExerciseStory
{
    public function __construct(private LessonRenderer $markdown)
    {
    }

    public function fromInstructions(string $instructions): string
    {
        return $this->firstParagraph($instructions);
    }

    /** Le premier vrai paragraphe d'un texte Markdown, en texte brut : un titre ou une amorce trop courte est sauté. */
    public function firstParagraph(string $source): string
    {
        $text = trim(html_entity_decode(strip_tags((string) preg_replace('#</(p|h[1-6]|li|ul|ol|pre|table|blockquote)>#', "\$0\n\n", $this->markdown->toHtml($source))), \ENT_QUOTES | \ENT_HTML5));
        foreach (preg_split('/\n\s*\n/', $text) ?: [] as $paragraph) {
            // Un titre répète celui de l'exercice : on cherche une vraie phrase.
            if (mb_strlen(trim($paragraph)) >= 40) {
                return trim($paragraph);
            }
        }

        return $text;
    }
}
