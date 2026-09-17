<?php

namespace App\Payment;

use App\Content\Track;
use App\Entity\PriceKind;
use App\Entity\User;
use App\Repository\PurchaseRepository;
use App\Repository\TrackPricingRepository;
use Psr\Clock\ClockInterface;

/**
 * Le prix courant d'un parcours : fondateur tant qu'il est actif et que ni sa date ni son quota ne sont dépassés,
 * normal sinon ; pour l'apprenant d'une cohorte financée par ses apprenants, le tarif de la cohorte s'il est fixé.
 */
final readonly class TrackPricer
{
    public function __construct(
        private TrackPricingRepository $pricings,
        private PurchaseRepository $purchases,
        private ClockInterface $clock,
    ) {
    }

    public function quote(Track $track, ?User $user = null): PriceQuote
    {
        $pricing = $this->pricings->findOneByTrack($track->id);
        if (null === $pricing || $pricing->isFree()) {
            return new PriceQuote(0, 0, PriceKind::Normal);
        }

        $cohort = $user?->getCohort();
        if (null !== $cohort && !$cohort->isFundedByInstitution() && null !== $cohort->getLearnerPrice() && $cohort->offersTrack($track->id)) {
            return new PriceQuote($cohort->getLearnerPrice(), $pricing->getNormalPrice(), PriceKind::Cohort, cohort: $cohort);
        }

        $now = $this->clock->now();
        $founderSales = $pricing->isFounderActive() ? $this->purchases->countFounderSales($track->id) : 0;
        if ($pricing->isFounderPriceApplicable($now, $founderSales)) {
            return new PriceQuote(
                $pricing->currentPrice($now, $founderSales),
                $pricing->getNormalPrice(),
                PriceKind::Founder,
                $pricing->getFounderEndsAt(),
                $pricing->founderSeatsLeft($founderSales),
            );
        }

        return new PriceQuote($pricing->getNormalPrice(), $pricing->getNormalPrice(), PriceKind::Normal);
    }
}
