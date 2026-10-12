<?php

namespace App\Tests\Share;

use App\Share\ShareCard;
use App\Share\ShareImageRenderer;
use PHPUnit\Framework\TestCase;

/** Le dessin d'une image de partage : un PNG au format des aperçus de liens, quel que soit le titre. */
final class ShareImageRendererTest extends TestCase
{
    private const array STYLE = ['image' => null, 'font' => null, 'text_font' => null, 'background' => '#f5f5f4', 'ink' => '#1c1917', 'accent' => '#1d4ed8', 'muted' => '#57534e'];

    public function testUnPngDe1200Sur630(): void
    {
        $png = (new ShareImageRenderer())->render(new ShareCard('Pratique · Symfony 8.2', 'Les sous-commandes d\'une commande de console', 'exemple.test'), self::STYLE);

        $size = getimagesizefromstring($png);
        $this->assertNotFalse($size);
        $this->assertSame([1200, 630, 'image/png'], [$size[0], $size[1], $size['mime']]);
    }

    public function testUnTitreAvecDesEntitesOuDesAccentsEnFinDeLigneSeDessine(): void
    {
        // « À » finit par l'octet 0x80, comme « … » : une coupe par octets laisserait de l'UTF-8 invalide.
        $png = (new ShareImageRenderer())->render(new ShareCard('Pratique · Twig', str_repeat('Échapper &amp; et &lt;script&gt; À ', 12), 'exemple.test'), self::STYLE);

        $this->assertSame([1200, 630], \array_slice((array) getimagesizefromstring($png), 0, 2));
    }

    public function testUnTitreTropLongEtUnFondFourniNeCassentRien(): void
    {
        $background = sys_get_temp_dir().'/fond-'.bin2hex(random_bytes(4)).'.png';
        $image = imagecreatetruecolor(600, 600);
        imagepng($image, $background);
        try {
            $png = (new ShareImageRenderer())->render(
                new ShareCard('', str_repeat('Anticonstitutionnellement ', 30).'Supercalifragilisticexpialidociouxdocious', ''),
                ['image' => $background] + self::STYLE,
            );
        } finally {
            unlink($background);
        }

        $this->assertSame([1200, 630], \array_slice((array) getimagesizefromstring($png), 0, 2));
    }
}
