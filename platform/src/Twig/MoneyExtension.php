<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** Montants en centimes affichés en euros : `{{ 4900|euros }}` → « 49,00 € ». Jamais de centimes bruts à l'écran. */
final class MoneyExtension extends AbstractExtension
{
    private ?\NumberFormatter $formatter = null;

    public function getFilters(): array
    {
        return [new TwigFilter('euros', $this->euros(...))];
    }

    public function euros(?int $cents): string
    {
        if (null === $cents) {
            return '';
        }
        $this->formatter ??= new \NumberFormatter('fr_FR', \NumberFormatter::CURRENCY);

        return (string) $this->formatter->formatCurrency($cents / 100, 'EUR');
    }
}
