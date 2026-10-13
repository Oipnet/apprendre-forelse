<?php

namespace App\Share;

use Symfony\Contracts\Translation\TranslatorInterface;

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

    /** Le texte alternatif de l'image : ce qu'elle dit, dans l'ordre où on le lit, dans la langue de la page. */
    public function alt(TranslatorInterface $translator): string
    {
        return '' === $this->eyebrow
            ? trim($this->title, ' :')
            : trim($translator->trans('share.carte.alt', ['surtitre' => $this->eyebrow, 'titre' => $this->title]), ' :');
    }
}
