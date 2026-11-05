<?php

namespace App\Tests;

use App\PaieDuLait;
use PHPUnit\Framework\TestCase;

final class PaieDuLaitTest extends TestCase
{
    public function testUnBidonVideNeRapporteRien(): void
    {
        $this->assertSame(0, (new PaieDuLait())->montantEnCentimes(0));
    }
}
