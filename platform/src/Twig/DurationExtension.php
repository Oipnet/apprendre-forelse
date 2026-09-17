<?php

namespace App\Twig;

use App\Content\Chapter;
use App\Content\ContentRepository;
use App\Content\Track;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Durées estimées, arrondies à ce qu'une estimation peut promettre : `{{ 20|duree }}` → « 20 min »,
 * `{{ 195|duree }}` → « 3 h 15 », `{{ 2785|duree }}` → « 46 h ». `track_duration(track, chapter?)` en minutes, ou null.
 */
final class DurationExtension extends AbstractExtension
{
    public function __construct(private readonly ContentRepository $content)
    {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('duree', self::format(...))];
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('track_duration', fn (Track $track, ?Chapter $chapter = null) => $this->content->durationOf($track, $chapter))];
    }

    public static function format(?int $minutes): string
    {
        if (null === $minutes || $minutes <= 0) {
            return '';
        }
        if ($minutes < 60) {
            return $minutes.' min';
        }
        if ($minutes < 600) {
            // Au quart d'heure près.
            $minutes = (int) (round($minutes / 15) * 15);
            $rest = $minutes % 60;

            return intdiv($minutes, 60).' h'.($rest ? ' '.str_pad((string) $rest, 2, '0', \STR_PAD_LEFT) : '');
        }

        return round($minutes / 60).' h';
    }

    /** Pour schema.org : « PT46H25M ». */
    public static function iso(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return 'PT'.($hours ? $hours.'H' : '').($rest || !$hours ? $rest.'M' : '');
    }
}
