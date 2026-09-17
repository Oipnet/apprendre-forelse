<?php

namespace App\Payment;

use App\Entity\Cohort;
use App\Entity\PriceKind;

/** Le prix d'un parcours pour un apprenant, maintenant (TTC, en centimes). */
final readonly class PriceQuote
{
    public function __construct(
        public int $price,
        public int $normalPrice,
        public PriceKind $kind,
        /** Fin annoncée du prix fondateur, s'il s'applique et en a une. */
        public ?\DateTimeImmutable $founderEndsAt = null,
        /** Places restantes au prix fondateur, s'il s'applique et a un quota. */
        public ?int $founderSeatsLeft = null,
        /** La cohorte dont le tarif s'applique. */
        public ?Cohort $cohort = null,
    ) {
    }

    /** Parcours gratuit : pas de bouton d'achat. */
    public function isFree(): bool
    {
        return $this->normalPrice <= 0;
    }

    /** Le prix normal s'affiche barré. */
    public function isDiscounted(): bool
    {
        return $this->price < $this->normalPrice;
    }

    public function isFounder(): bool
    {
        return PriceKind::Founder === $this->kind;
    }
}
