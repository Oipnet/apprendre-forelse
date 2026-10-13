<?php

namespace App\Defense;

use App\Entity\Defense;
use App\Entity\DefenseOutcome;

/** Où en est un apprenant de sa soutenance sur un parcours : ce que la page du parcours lui montre. */
final readonly class DefenseOffer
{
    /**
     * @param list<Defense> $slots les créneaux qu'il peut réserver (vide s'il ne le peut pas)
     */
    public function __construct(
        /** Tous les exercices du parcours sont réussis : la soutenance lui est ouverte. */
        public bool $trackComplete,
        /** Son créneau réservé, pas encore évalué. */
        public ?Defense $upcoming,
        /** Sa dernière soutenance évaluée. */
        public ?Defense $last,
        public array $slots,
    ) {
    }

    public function passed(): bool
    {
        return DefenseOutcome::Passed === $this->last?->getOutcome();
    }

    /** Il peut réserver un créneau : parcours terminé, rien de réservé, pas encore validée. */
    public function canBook(): bool
    {
        return $this->trackComplete && null === $this->upcoming && !$this->passed();
    }
}
