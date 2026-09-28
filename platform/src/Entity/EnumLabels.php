<?php

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Les libellés d'une enum, pour l'administration : traduisible (EasyAdmin affiche label() tel quel), et ses
 * listes de choix dérivées des cas — un cas ajouté y paraît sans retoucher les CRUD. Un ChoiceField lié à un champ
 * enumType n'a pas besoin de setChoices() : EasyAdmin tire les choix de l'enum, et leurs libellés de trans().
 */
trait EnumLabels
{
    abstract public function label(): string;

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }

    /** @return array<string, string> libellé => valeur, pour un ChoiceFilter */
    public static function valueChoices(): array
    {
        return array_combine(array_map(static fn (self $case) => $case->label(), self::cases()), array_column(self::cases(), 'value'));
    }
}
