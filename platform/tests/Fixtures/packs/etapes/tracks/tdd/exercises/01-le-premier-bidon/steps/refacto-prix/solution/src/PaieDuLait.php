<?php

namespace App;

final class PaieDuLait
{
    public const int PRIX_DU_LITRE = 50;

    public function montantEnCentimes(int $litres): int
    {
        return $litres * self::PRIX_DU_LITRE;
    }
}
