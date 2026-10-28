<?php

namespace App;

final class Salutation
{
    public function pour(string $prenom): string
    {
        return \sprintf('Hello %s!', $prenom);
    }
}
