<?php

namespace App;

final class Salutation
{
    public function pour(string $prenom): string
    {
        return \sprintf('Bonjour %s !', $prenom);
    }
}
