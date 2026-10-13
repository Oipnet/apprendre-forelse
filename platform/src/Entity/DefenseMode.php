<?php

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Où se passe une soutenance : en visio (lien de l'école) ou sur place (adresse, salle). */
enum DefenseMode: string implements TranslatableInterface
{
    use EnumLabels;

    case Online = 'online';
    case OnSite = 'on_site';

    /** Le libellé montré à l'apprenant, dans la langue de la page ; label() garde le français de l'administration. */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('defense.mode.'.$this->value, [], 'apprentissage', $locale);
    }

    public function label(): string
    {
        return match ($this) {
            self::Online => 'En ligne',
            self::OnSite => 'En présentiel',
        };
    }

    /** Ce que contient le champ « lieu » du créneau. */
    public function placeLabel(): string
    {
        return match ($this) {
            self::Online => 'Lien de la visio',
            self::OnSite => 'Adresse et salle',
        };
    }
}
