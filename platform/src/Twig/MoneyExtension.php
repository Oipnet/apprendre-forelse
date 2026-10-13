<?php

namespace App\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Montants en centimes affichés en euros, dans la langue de la page : `{{ 4900|euros }}` → « 49,00 € ». Jamais de
 * centimes bruts à l'écran.
 */
final class MoneyExtension extends AbstractExtension
{
    /** @var array<string, \NumberFormatter> par langue : rien qui dépende d'une requête, rien à oublier entre deux */
    private array $formatters = [];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('euros', $this->euros(...))];
    }

    public function euros(?int $cents): string
    {
        if (null === $cents) {
            return '';
        }
        $locale = $this->translator->getLocale();
        $this->formatters[$locale] ??= new \NumberFormatter($locale, \NumberFormatter::CURRENCY);

        return (string) $this->formatters[$locale]->formatCurrency($cents / 100, 'EUR');
    }
}
