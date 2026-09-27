<?php

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Les libellés d'une enum, pour l'administration : traduisible (EasyAdmin affiche label() tel quel), et ses
 * listes de choix dérivées des cas — un cas ajouté y paraît sans retoucher les CRUD.
 */
trait EnumLabels
{
    abstract public function label(): string;

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }

    /** @return array<string, self> libellé => cas, pour un ChoiceField lié à un champ enumType */
    public static function choices(): array
    {
        return array_combine(array_map(static fn (self $case) => $case->label(), self::cases()), self::cases());
    }

    /** @return array<string, string> libellé => valeur, pour un ChoiceFilter */
    public static function valueChoices(): array
    {
        return array_combine(array_map(static fn (self $case) => $case->label(), self::cases()), array_column(self::cases(), 'value'));
    }
}
