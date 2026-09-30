<?php

namespace App\Tests\Mail;

use App\Legal\LegalInfo;
use App\Mail\Sender;
use App\Tests\ThemeTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/** L'expéditeur des emails et leur adresse de réponse, d'une instance à l'autre. */
final class SenderTest extends TestCase
{
    use ThemeTrait;

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/theme-'.bin2hex(random_bytes(6));
        (new Filesystem())->dumpFile($this->tmp.'/theme.yaml', "name: Atelier Bigorneau\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    public function testUneAdresseSansNomPrendCeluiDeLaMarque(): void
    {
        $sender = new Sender(self::theme($this->tmp), new LegalInfo(), 'ne-pas-repondre@bigorneau.test', '');

        $this->assertSame('"Atelier Bigorneau" <ne-pas-repondre@bigorneau.test>', $sender->from()->toString());
    }

    public function testUnNomDeclareDansMailerFromEstGarde(): void
    {
        $sender = new Sender(self::theme($this->tmp), new LegalInfo(), 'Bigorneau Formation <ne-pas-repondre@bigorneau.test>', '');

        $this->assertSame('Bigorneau Formation', $sender->from()->getName());
    }

    public function testLaReponseVaALAdresseDeContactSiIlYEnAUne(): void
    {
        $avecContact = new Sender(self::theme($this->tmp), new LegalInfo(publisherEmail: 'editeur@bigorneau.test'), 'ne-pas-repondre@bigorneau.test', 'contact@bigorneau.test');
        $this->assertSame(['contact@bigorneau.test'], array_map(static fn ($a) => $a->getAddress(), $avecContact->email()->getReplyTo()));

        $mentionsLegales = new Sender(self::theme($this->tmp), new LegalInfo(publisherEmail: 'editeur@bigorneau.test'), 'ne-pas-repondre@bigorneau.test', '');
        $this->assertSame('editeur@bigorneau.test', $mentionsLegales->contact(), 'À défaut, l\'adresse des mentions légales.');

        $sansRien = new Sender(self::theme($this->tmp), new LegalInfo(), 'ne-pas-repondre@bigorneau.test', '');
        $this->assertSame([], $sansRien->email()->getReplyTo(), 'Aucune adresse : pas de Reply-To vers nulle part.');
    }
}
