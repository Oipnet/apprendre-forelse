<?php

namespace App\Tests\Verification;

use App\PaieDuLait;
use PHPUnit\Framework\TestCase;

final class PaieDuLaitVerificationTest extends TestCase
{
    public function testVerifieUnBidonVide(): void
    {
        $this->assertSame(0, (new PaieDuLait())->montantEnCentimes(0));
    }

    public function testVerifieLePrixDuLitre(): void
    {
        $this->assertSame(500, (new PaieDuLait())->montantEnCentimes(10));
        $this->assertSame(150, (new PaieDuLait())->montantEnCentimes(3));
    }

    public function testLePrixDuLitreEstUneConstante(): void
    {
        $this->assertTrue((new \ReflectionClass(PaieDuLait::class))->hasConstant('PRIX_DU_LITRE'), 'PaieDuLait::PRIX_DU_LITRE n\'existe pas.');
        $this->assertSame(50, (new \ReflectionClassConstant(PaieDuLait::class, 'PRIX_DU_LITRE'))->getValue());
    }
}
