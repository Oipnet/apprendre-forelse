<?php

namespace App\Cohort;

/** Estimation du devis d'une cohorte (TTC, en centimes). Le montant contractuel reste celui saisi par l'administrateur. */
final readonly class CohortQuote
{
    public function __construct(
        public int $headcount,
        public int $trackCount,
        /** Prix par apprenant et par parcours, avant dégressivité. */
        public int $unitPrice,
        /** Pourcentage du palier atteint par l'effectif (100 = plein tarif). */
        public int $percent,
        public int $amount,
    ) {
    }

    /** Le devis saisi ne correspond plus à l'estimation (effectif ou parcours changés depuis) : à revoir. */
    public function differsFrom(?int $quoteAmount): bool
    {
        return null !== $quoteAmount && $quoteAmount !== $this->amount;
    }
}
