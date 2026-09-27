<?php

namespace App\Entity;

/**
 * La couleur de chaque cas, déclarée par l'enum : un cas ajouté ne peut plus s'afficher sans badge (le
 * `match` de badge() ne compile pas sans lui), ce qui était arrivé à PurchaseStatus::Disputed.
 */
trait EnumBadges
{
    /** Classe de badge EasyAdmin : success, warning, danger, info, secondary, light… */
    abstract public function badge(): string;

    /** @return array<string, string> nom du cas => badge, pour ChoiceField::renderAsBadges() */
    public static function badges(): array
    {
        return array_combine(array_column(self::cases(), 'name'), array_map(static fn (self $case) => $case->badge(), self::cases()));
    }
}
