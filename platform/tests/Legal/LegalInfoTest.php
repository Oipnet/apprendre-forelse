<?php

namespace App\Tests\Legal;

use App\Legal\LegalInfo;
use PHPUnit\Framework\TestCase;

final class LegalInfoTest extends TestCase
{
    public function testRienDeRenseigne(): void
    {
        $legal = new LegalInfo();

        $this->assertFalse($legal->complete());
        $this->assertSame([
            'LEGAL_PUBLISHER_NAME', 'LEGAL_PUBLISHER_LEGAL_FORM', 'LEGAL_PUBLISHER_REGISTRATION', 'LEGAL_PUBLISHER_ADDRESS',
            'LEGAL_PUBLISHER_EMAIL', 'LEGAL_PUBLICATION_DIRECTOR', 'LEGAL_HOST_NAME', 'LEGAL_HOST_ADDRESS',
        ], $legal->missing());
    }

    public function testLesFacultatifsNeManquentJamais(): void
    {
        $legal = new LegalInfo(
            publisherName: 'Exemple SAS',
            publisherLegalForm: 'SAS au capital de 1 000 €',
            publisherRegistration: 'RCS Lyon 123 456 789',
            publisherAddress: '1 rue de l\'Exemple, 69000 Lyon',
            publisherEmail: 'contact@example.test',
            publicationDirector: 'Ada Lovelace',
            hostName: 'Hébergeur Test',
            hostAddress: '2 rue des Serveurs, 59100 Roubaix',
        );

        $this->assertTrue($legal->complete(), 'TVA et téléphones sont facultatifs.');
    }

    public function testUneValeurBlancheCompteCommeVide(): void
    {
        $this->assertContains('LEGAL_PUBLISHER_NAME', (new LegalInfo(publisherName: '   '))->missing());
    }
}
