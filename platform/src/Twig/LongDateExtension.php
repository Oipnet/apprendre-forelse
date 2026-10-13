<?php

namespace App\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `{{ article.published|date_longue }}` → « 6 octobre 2026 » : la date d'un texte, telle qu'on l'écrit, dans la
 * langue de la page (celle du traducteur).
 */
final class LongDateExtension extends AbstractExtension
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('date_longue', $this->format(...))];
    }

    public function format(\DateTimeInterface $date): string
    {
        // Le fuseau de la date : une date de pack (minuit UTC) ne recule pas d'un jour à Paris.
        $formatter = new \IntlDateFormatter($this->translator->getLocale(), \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, $date->getTimezone());

        return (string) $formatter->format($date);
    }
}
