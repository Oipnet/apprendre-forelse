<?php

namespace App\Cohort;

use App\Content\ContentRepository;
use App\Content\Track;
use App\Entity\Cohort;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Estime le devis d'une cohorte : effectif prévu × nombre de parcours × prix unitaire, avec un barème dégressif
 * par paliers d'effectif. Prix et paliers se règlent par variables d'environnement (COHORT_UNIT_PRICE,
 * COHORT_TIERS) : une instance a ses propres tarifs sans reconstruire l'image.
 */
final readonly class CohortQuoteEstimator
{
    /** @var list<array{from: int, percent: int}> paliers, par effectif croissant */
    private array $tiers;

    /**
     * @param string $tiers barème « effectif:pourcentage », séparés par des virgules (« 1:100,10:70,30:50 »)
     */
    public function __construct(
        private ContentRepository $content,
        #[Autowire(env: 'int:COHORT_UNIT_PRICE')]
        private int $unitPrice,
        #[Autowire(env: 'COHORT_TIERS')]
        string $tiers,
    ) {
        $this->tiers = self::parseTiers($tiers);
    }

    /**
     * @return list<array{from: int, percent: int}> paliers, par effectif croissant
     */
    private static function parseTiers(string $tiers): array
    {
        $parsed = [];
        foreach (array_filter(array_map('trim', explode(',', $tiers))) as $tier) {
            if (1 !== preg_match('/^(\\d+):(\\d+)$/', $tier, $matches)) {
                throw new \InvalidArgumentException(sprintf('COHORT_TIERS : « %s » n\'est pas un palier « effectif:pourcentage » (ex. « 1:100,10:70,30:50 »).', $tier));
            }
            $parsed[] = ['from' => (int) $matches[1], 'percent' => (int) $matches[2]];
        }
        usort($parsed, static fn (array $a, array $b) => $a['from'] <=> $b['from']);

        return $parsed;
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
