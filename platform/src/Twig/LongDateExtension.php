<?php

namespace App\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `{{ article.published|date_longue }}` → « 6 octobre 2026 » : la date d'un texte, telle qu'on l'écrit, dans la
 * langue de la page (celle du traducteur). `{{ date|date_motif('LLLL y') }}` → « octobre 2026 » : un motif ICU, pour
 * un mois ou un jour seul.
 */
final class LongDateExtension extends AbstractExtension
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('date_longue', $this->format(...)), new TwigFilter('date_motif', $this->pattern(...))];
    }

    public function format(\DateTimeInterface $date): string
    {
        // Le fuseau de la date : une date de pack (minuit UTC) ne recule pas d'un jour à Paris.
        $formatter = new \IntlDateFormatter($this->translator->getLocale(), \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, $date->getTimezone());

        return (string) $formatter->format($date);
    }

    public function pattern(\DateTimeInterface $date, string $pattern): string
    {
        $formatter = new \IntlDateFormatter($this->translator->getLocale(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $date->getTimezone(), pattern: $pattern);

        return (string) $formatter->format($date);
    }
}
