<?php

namespace App\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Un nombre entier mis en forme dans la langue de la page : `{{ 12500|nombre }}` → « 12 500 ». En français, les
 * milliers sont séparés par une espace ordinaire (et non l'espace fine insécable de la norme), comme le site l'a
 * toujours écrit.
 */
final class NumberExtension extends AbstractExtension
{
    /** @var array<string, \NumberFormatter> par langue : rien qui dépende d'une requête, rien à oublier entre deux */
    private array $formatters = [];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('nombre', $this->format(...))];
    }

    public function format(int|float|string|null $number): string
    {
        $locale = $this->translator->getLocale();

        return (string) ($this->formatters[$locale] ??= self::formatter($locale))->format((float) $number);
    }

    private static function formatter(string $locale): \NumberFormatter
    {
        $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 0);
        $formatter->setAttribute(\NumberFormatter::ROUNDING_MODE, \NumberFormatter::ROUND_HALFUP);
        if ('fr' === \Locale::getPrimaryLanguage($locale)) {
            $formatter->setSymbol(\NumberFormatter::GROUPING_SEPARATOR_SYMBOL, ' ');
        }

        return $formatter;
    }
}
