<?php

namespace App\Payment;

use App\Content\Track;
use App\Entity\User;
use App\Legal\LegalInfo;
use App\Security\TrackAccessChecker;

final readonly class TrackOfferFactory
{
    public function __construct(
        private TrackPricer $pricer,
        private TrackAccessChecker $access,
        private PaymentGateway $gateway,
        private LegalInfo $legal,
    ) {
    }

    public function create(Track $track, ?User $user): TrackOffer
    {
        return new TrackOffer($this->pricer->quote($track, $user), $this->access->hasFullAccess($user, $track), $this->gateway->isConfigured() && $this->legal->canSell());
    }
}
