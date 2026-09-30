<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** `{{ article.published|date_longue }}` → « 6 octobre 2026 » : la date d'un texte, telle qu'on l'écrit. */
final class FrenchDateExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('date_longue', self::format(...))];
    }

    public static function format(\DateTimeInterface $date): string
    {
        // Le fuseau de la date : une date de pack (minuit UTC) ne recule pas d'un jour à Paris.
        $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, $date->getTimezone());

        return (string) $formatter->format($date);
    }
}
