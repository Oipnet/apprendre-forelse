<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** Filtres d'affichage de la boutique. Le filtre « prix » évite la dépendance intl. */
final class BoutiqueExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('prix', $this->prix(...)),
        ];
    }

    public function prix(string|float $montant): string
    {
        return number_format((float) $montant, 2, ',', ' ').' €';
    }
}
