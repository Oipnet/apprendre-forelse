<?php

namespace App\Twig;

use App\Content\Chapter;
use App\Content\ContentRepository;
use App\Content\Track;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Durées estimées, arrondies à ce qu'une estimation peut promettre : `{{ 20|duree }}` → « 20 min »,
 * `{{ 195|duree }}` → « 3 h 15 », `{{ 2785|duree }}` → « 46 h » (unités : format.duree.* du domaine messages).
 * `track_duration(track, chapter?)` en minutes, ou null.
 */
final class DurationExtension extends AbstractExtension
{
    public function __construct(
        private readonly ContentRepository $content,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('duree', $this->format(...))];
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('track_duration', fn (Track $track, ?Chapter $chapter = null) => $this->content->durationOf($track, $chapter))];
    }

    public function format(?int $minutes): string
    {
        if (null === $minutes || $minutes <= 0) {
            return '';
        }
        if ($minutes < 60) {
            return $this->translator->trans('format.duree.minutes', ['minutes' => (string) $minutes]);
        }
        if ($minutes < 600) {
            // Au quart d'heure près.
            $minutes = (int) (round($minutes / 15) * 15);
            $rest = $minutes % 60;

            return $rest
                ? $this->translator->trans('format.duree.heures_minutes', ['heures' => (string) intdiv($minutes, 60), 'minutes' => str_pad((string) $rest, 2, '0', \STR_PAD_LEFT)])
                : $this->translator->trans('format.duree.heures', ['heures' => (string) intdiv($minutes, 60)]);
        }

        return $this->translator->trans('format.duree.heures', ['heures' => (string) round($minutes / 60)]);
    }

    /** Pour schema.org : « PT46H25M ». */
    public static function iso(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return 'PT'.($hours ? $hours.'H' : '').($rest || !$hours ? $rest.'M' : '');
    }
}
