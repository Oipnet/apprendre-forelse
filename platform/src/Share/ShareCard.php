<?php

namespace App\Share;

/**
 * Ce qu'une image de partage écrit : un surtitre (« Pratique · Symfony 8.2 »), un titre, un pied (le site).
 * Le même dessin sert à un exercice comme à un parcours : seul le texte change (voir ShareImage).
 */
final readonly class ShareCard
{
    public function __construct(
        public string $eyebrow,
        public string $title,
        public string $footer,
    ) {
    }

    /** Le texte alternatif de l'image : ce qu'elle dit, dans l'ordre où on le lit. */
    public function alt(): string
    {
        return trim($this->eyebrow.' : '.$this->title, ' :');
    }
}
