<?php

namespace App\Tests\Security;

use App\Security\SafeRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SafeRedirectTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string}> */
    public static function targets(): iterable
    {
        yield 'chemin du site' => ['/parcours/decouverte?x=1', '/parcours/decouverte?x=1'];
        yield 'racine' => ['/', '/'];
        yield 'rien' => [null, null];
        yield 'vide' => ['', null];
        yield 'relatif' => ['parcours', null];
        yield 'double barre' => ['//evil.test', null];
        yield 'barre inverse' => ['/\\evil.test', null];
        yield 'tabulation, que le navigateur retire' => ["/\t/evil.test", null];
        yield 'retour à la ligne' => ["/\n/evil.test", null];
        yield 'espace' => ['/ /evil.test', null];
        yield 'adresse complète' => ['https://evil.test/', null];
    }

    #[DataProvider('targets')]
    public function testSeulUnCheminLocalEstGarde(?string $target, ?string $expected): void
    {
        $this->assertSame($expected, SafeRedirect::localPath($target));
    }

    public function testUneAdresseDeLaMemeOrigineEstGardee(): void
    {
        $this->assertSame('https://site.test/admin/retours', SafeRedirect::sameOrigin('https://site.test/admin/retours', 'https://site.test'));
        $this->assertNull(SafeRedirect::sameOrigin('https://site.test.evil.test/', 'https://site.test'), 'Un domaine qui commence pareil.');
        $this->assertNull(SafeRedirect::sameOrigin('http://site.test/', 'https://site.test'), 'Un autre schéma.');
        $this->assertNull(SafeRedirect::sameOrigin(null, 'https://site.test'));
    }
}
