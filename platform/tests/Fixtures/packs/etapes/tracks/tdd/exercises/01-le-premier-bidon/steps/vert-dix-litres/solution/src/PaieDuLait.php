<?php

namespace App;

final class PaieDuLait
{
    public function montantEnCentimes(int $litres): int
    {
        return $litres * 50;
    }
}
