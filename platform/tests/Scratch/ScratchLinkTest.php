<?php

namespace App\Tests\Scratch;

use App\Scratch\ScratchLink;
use PHPUnit\Framework\TestCase;

/** Le lien partagé du bac à sable, côté serveur : le même format que la page (playground/src/app/scratch.ts). */
final class ScratchLinkTest extends TestCase
{
    /**
     * Fabriqué par la page (fflate, JSON.stringify) : {v: 1, files: {"routes/web.php": "<?php // é 🎉", "a/../x": "bad"},
     * packages: ["x"]}. Dans l'autre sens, playground/tests/scratch.test.ts relit un lien fabriqué ici.
     */
    private const string FROM_THE_PAGE = 'code=q1YqU7Iy1FFKy8xJLVayqlYqyi8tSS3WL09N0ivIKFCyUrKxB9IK-voKh1cqfJjf16mko5Sor6enXwGUS0pMUarVUSpITM5OTAfpj1aqUIqtBQA';

    public function testUnLienSeRelitAvecSesFichiersEtSesPaquets(): void
    {
        $links = new ScratchLink();
        $files = ['src/Controller/AccueilController.php' => "<?php\n// Bonjour é 🎉 \"guillemets\" / \\", 'templates/accueil.html.twig' => '{{ titre }}'];

        $fragment = $links->encode($files, ['nesbot/carbon']);

        $this->assertStringStartsWith('code=', $fragment);
        $this->assertMatchesRegularExpression('/^code=[A-Za-z0-9_-]+$/', $fragment, 'base64url, sans « = » : rien à échapper dans une adresse.');
        $this->assertSame(['files' => $files, 'packages' => ['nesbot/carbon']], $links->decode('https://apprendre.forelse.fr/bac-a-sable/symfony?utm_source=mcp#'.$fragment));
        $this->assertSame(['files' => [], 'packages' => []], $links->decode($links->encode([])), 'Un projet inchangé reste un objet JSON vide.');
    }

    public function testUnLienFabriqueParLaPageSeRelitEtSesCheminsPiegesSontEcartes(): void
    {
        $this->assertSame(['files' => ['routes/web.php' => '<?php // é 🎉'], 'packages' => ['x']], (new ScratchLink())->decode(self::FROM_THE_PAGE));
    }

    public function testUnFragmentIllisibleNeDonneRien(): void
    {
        $links = new ScratchLink();

        $this->assertNull($links->decode('nouveau'));
        $this->assertNull($links->decode('code=pas du base64 !'));
        $this->assertNull($links->decode('code='.rtrim(strtr(base64_encode((string) gzdeflate('{"v":2,"files":{}}')), '+/', '-_'), '=')));
        $this->assertNull($links->decode('code='.str_repeat('A', ScratchLink::MAX_FRAGMENT)));
        // Une bombe : quelques kilo-octets qui en feraient bien plus d'un million une fois décompressés.
        $bomb = rtrim(strtr(base64_encode((string) gzdeflate('{"v":1,"files":{"a.php":"'.str_repeat('a', 2 * ScratchLink::MAX_BYTES).'"}}', 9)), '+/', '-_'), '=');
        $this->assertNull($links->decode('code='.$bomb));
    }

    public function testUnProjetTropGrosPourLaPageEstRefuse(): void
    {
        $this->expectException(\LengthException::class);

        (new ScratchLink())->encode(['a.php' => bin2hex(random_bytes(150_000))]);
    }
}
