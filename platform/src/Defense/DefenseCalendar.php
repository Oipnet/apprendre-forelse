<?php

namespace App\Defense;

use App\Content\Track;
use App\Entity\Defense;
use App\Entity\DefenseMode;
use App\Theme\Theme;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * L'invitation d'agenda (iCalendar, RFC 5545) d'une soutenance réservée : jointe à la convocation, et téléchargeable
 * depuis la page du parcours. Toujours le même UID : un agenda qui la reçoit deux fois met l'événement à jour.
 */
final readonly class DefenseCalendar
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private Theme $theme,
        private TranslatorInterface $translator,
    ) {
    }

    public static function filename(Defense $defense): string
    {
        return sprintf('soutenance-%s.ics', $defense->getStartsAt()->format('Y-m-d'));
    }

    public function ics(Defense $defense, Track $track): string
    {
        $url = $this->urls->generate('app_track', ['trackId' => $track->id], UrlGeneratorInterface::ABSOLUTE_URL);
        $utc = new \DateTimeZone('UTC');
        $description = $this->translator->trans('defense.agenda.description', ['parcours' => $track->title, 'mode' => $defense->getMode()->trans($this->translator), 'lieu' => $defense->getPlace()], 'apprentissage');
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//'.self::escape($this->theme->name()).'//Soutenances//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            sprintf('UID:soutenance-%d@%s', $defense->getId(), parse_url($url, \PHP_URL_HOST) ?: 'localhost'),
            'DTSTAMP:'.($defense->getBookedAt() ?? $defense->getCreatedAt())->setTimezone($utc)->format('Ymd\THis\Z'),
            'DTSTART:'.$defense->getStartsAt()->setTimezone($utc)->format('Ymd\THis\Z'),
            'DTEND:'.$defense->getEndsAt()->setTimezone($utc)->format('Ymd\THis\Z'),
            'SUMMARY:'.self::escape($this->translator->trans('defense.agenda.titre', ['parcours' => $track->title], 'apprentissage')),
            'LOCATION:'.self::escape($defense->getPlace()),
            'DESCRIPTION:'.self::escape($description),
            'URL:'.(DefenseMode::Online === $defense->getMode() ? $defense->getPlace() : $url),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /** Texte d'une propriété : antislash, point-virgule, virgule et retours à la ligne échappés. */
    private static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $text);
    }

    /** Lignes de 75 octets au plus, la suite commençant par une espace, sans couper un caractère UTF-8. */
    private static function fold(string $line): string
    {
        $folded = '';
        $current = '';
        foreach (mb_str_split($line) as $char) {
            if (\strlen($current) + \strlen($char) > ('' === $folded ? 75 : 74)) {
                $folded .= ('' === $folded ? '' : "\r\n ").$current;
                $current = '';
            }
            $current .= $char;
        }

        return $folded.('' === $folded ? '' : "\r\n ").$current;
    }
}
