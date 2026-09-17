<?php

namespace App\Cohort;

use App\Content\ContentRepository;
use App\Content\Track;
use App\Entity\Cohort;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Estime le devis d'une cohorte : effectif prévu × nombre de parcours × prix unitaire, avec un barème dégressif
 * par paliers d'effectif. Prix et paliers se règlent dans config/services.yaml (app.cohort_quote.*).
 */
final readonly class CohortQuoteEstimator
{
    /** @var list<array{from: int, percent: int}> paliers, par effectif croissant */
    private array $tiers;

    /**
     * @param list<array{from: int, percent: int}> $tiers
     */
    public function __construct(
        private ContentRepository $content,
        #[Autowire('%app.cohort_quote.unit_price%')]
        private int $unitPrice,
        #[Autowire('%app.cohort_quote.tiers%')]
        array $tiers,
    ) {
        usort($tiers, static fn (array $a, array $b) => $a['from'] <=> $b['from']);
        $this->tiers = $tiers;
    }

    public function estimate(Cohort $cohort): CohortQuote
    {
        // Sans sélection (possible en mode apprenants seulement), la cohorte propose tous les parcours publics.
        $trackCount = $cohort->hasTrackSelection()
            ? \count($cohort->getAvailableTrackIds())
            : \count(array_filter($this->content->tracks(), static fn (Track $track) => !$track->isRestricted()));

        return $this->compute($cohort->getExpectedHeadcount(), $trackCount);
    }

    public function compute(int $headcount, int $trackCount): CohortQuote
    {
        $percent = 100;
        foreach ($this->tiers as $tier) {
            if ($headcount >= $tier['from']) {
                $percent = $tier['percent'];
            }
        }
        $amount = (int) round($headcount * $trackCount * $this->unitPrice * $percent / 100);

        return new CohortQuote($headcount, $trackCount, $this->unitPrice, $percent, $amount);
    }
}
