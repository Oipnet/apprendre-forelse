<?php

namespace App\Seo;

use App\Content\Exercise;
use App\Content\Track;
use App\Entity\TrackSeo;
use App\Theme\Theme;
use App\Repository\TrackPricingRepository;
use App\Repository\TrackSeoRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Title et description d'une page de parcours. Ce qui est saisi dans l'admin (TrackSeo) passe avant la génération :
 *  - title : « Formation Symfony en ligne pour les devs PHP », tiré du titre du parcours quand il commence par le
 *    framework suivi de « pour … » ; sinon « Formation Symfony en ligne : Découverte ». Toujours suivi du nom
 *    de l'instance (« | <nom du thème> ») ;
 *  - description : le résumé du parcours, puis les chiffres (« 12 chapitres, 74 exercices, premier chapitre gratuit. »).
 *
 * Les textes générés viennent du domaine de traduction seo (parcours.*), dans la langue de la page. La détection du
 * public (« pour … », « for … ») et la coupe sur un mot lisent le titre et le résumé du parcours dans sa langue.
 * Ce que l'admin saisit est en français : il ne vaut que pour le parcours français, une traduction est générée.
 */
final readonly class TrackSeoText
{
    /** Longueur conseillée du title, sans le « | <marque> » ajouté à l'affichage. */
    public const int TITLE_MAX = 60;
    /** En deçà, une description en phrases entières est jugée trop maigre : on coupe plutôt sur un mot. */
    public const int DESCRIPTION_MIN = 120;

    public function __construct(
        private TrackSeoRepository $overrides,
        private TrackPricingRepository $pricings,
        private LoggerInterface $logger,
        private Theme $theme,
        private TranslatorInterface $translator,
    ) {
    }

    public function title(Track $track, string $framework): string
    {
        $title = $this->override($track)?->getSeoTitle() ?? $this->generatedTitle($track->title, $framework);
        if (mb_strlen($title) > self::TITLE_MAX) {
            $this->logger->warning('Title du parcours « {track} » trop long : {length} caractères, {max} au plus sans « | {site} ».', [
                'track' => $track->id, 'length' => mb_strlen($title), 'max' => self::TITLE_MAX, 'site' => $this->theme->name(), 'title' => $title,
            ]);
        }

        return $title.' | '.$this->theme->name();
    }

    public function description(Track $track): string
    {
        return $this->override($track)?->getSeoDescription() ?? $this->generatedDescription(
            $track->description,
            \count($track->chapters),
            \count($track->exerciseIds()),
            // « Gratuit » seulement pour un tarif enregistré à 0 € : sans tarif, le parcours est seulement « pas encore tarifé ».
            $this->pricings->findOneByTrack($track->id)?->isFree() ?? false,
        );
    }

    private function override(Track $track): ?TrackSeo
    {
        return Exercise::LOCALE === $track->locale ? $this->overrides->findOneByTrack($track->id) : null;
    }

    public function generatedTitle(string $trackTitle, string $framework): string
    {
        $audience = self::audience($trackTitle, $framework);

        return null !== $audience
            ? $this->translator->trans('parcours.titre_public', ['framework' => $framework, 'public' => $audience], 'seo')
            : $this->translator->trans('parcours.titre', ['framework' => $framework, 'parcours' => self::clean($trackTitle)], 'seo');
    }

    /** « Symfony pour les devs PHP » → « pour les devs PHP » (« Symfony for PHP devs » → « for PHP devs ») ; null sinon. */
    public static function audience(string $trackTitle, string $framework): ?string
    {
        $pattern = sprintf('/^%s\s+((?:pour|for)\s+\p{L}.{2,})$/iu', preg_quote($framework, '/'));

        return 1 === preg_match($pattern, self::clean($trackTitle), $matches) ? rtrim($matches[1], ' .') : null;
    }

    public function generatedDescription(string $summary, int $chapters, int $exercises, bool $free): string
    {
        $figures = $this->translator->trans('parcours.chiffres', ['chapitres' => $chapters, 'exercices' => $exercises, 'gratuit' => $free ? 'oui' : 'non'], 'seo');
        $summary = self::clean($summary);
        $budget = PageSeo::DESCRIPTION_MAX - mb_strlen($figures) - 1;
        if ('' === $summary) {
            return $figures;
        }
        if (mb_strlen($summary) <= $budget) {
            return $summary.' '.$figures;
        }

        // Trop long : les phrases entières qui tiennent, sinon (trop peu de texte) une coupe sur un mot.
        $kept = '';
        foreach (preg_split('/(?<=[.?!…])\s+/u', $summary) ?: [] as $sentence) {
            if (mb_strlen(ltrim($kept.' '.$sentence)) > $budget) {
                break;
            }
            $kept = ltrim($kept.' '.$sentence);
        }
        if (mb_strlen($kept) + 1 + mb_strlen($figures) < self::DESCRIPTION_MIN) {
            // « … le site de… » se lit mal : la coupe ne finit pas sur un petit mot.
            $kept = (string) preg_replace('/(?:\s+(?:de|du|des|la|le|les|un|une|à|au|aux|et|ou|en|pour|par|sur|avec|dans))+…$/iu', '…', PageSeo::shorten($summary, $budget));
        }

        return $kept.' '.$figures;
    }

    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
