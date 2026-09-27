<?php

namespace App\Entity;

/** Ce qui garde la date de sa dernière modification : TouchListener la pose, avec l'horloge, à chaque écriture. */
interface Touchable
{
    public function touch(\DateTimeImmutable $now): void;
}
