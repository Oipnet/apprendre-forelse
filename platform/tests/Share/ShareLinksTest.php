<?php

namespace App\Tests\Share;

use App\Share\ShareLinks;
use PHPUnit\Framework\TestCase;

/** Les liens de partage : l'adresse de chaque réseau, et ce qu'Umami lira de la visite qui en reviendra. */
final class ShareLinksTest extends TestCase
{
    public function testChaqueLienDitDOuViendraLaVisite(): void
    {
        $links = ShareLinks::of('https://exemple.test/pratique/point-precis', 'Un exercice « court » & utile', 'exercice');

        $this->assertSame('Un exercice « court » & utile', $links['text']);
        $this->assertSame('https://exemple.test/pratique/point-precis?utm_source=lien&utm_medium=partage&utm_campaign=exercice', $links['lien']);
        $this->assertSame('https://exemple.test/pratique/point-precis?utm_source=natif&utm_medium=partage&utm_campaign=exercice', $links['natif']);

        $this->assertSame('https://exemple.test/pratique/point-precis?utm_source=linkedin&utm_medium=partage&utm_campaign=exercice', self::query($links['linkedin'], 'https://www.linkedin.com/sharing/share-offsite/')['url']);

        $x = self::query($links['x'], 'https://x.com/intent/post');
        $this->assertSame('Un exercice « court » & utile', $x['text']);
        $this->assertStringContainsString('utm_source=x&', $x['url']);

        $bluesky = self::query($links['bluesky'], 'https://bsky.app/intent/compose');
        $this->assertSame('Un exercice « court » & utile https://exemple.test/pratique/point-precis?utm_source=bluesky&utm_medium=partage&utm_campaign=exercice', $bluesky['text'], 'Bluesky : l\'adresse suit le texte.');
    }

    public function testUneAdresseQuiAUneRequeteLaGarde(): void
    {
        $this->assertSame('https://exemple.test/p?a=1&utm_source=lien&utm_medium=partage&utm_campaign=parcours', ShareLinks::of('https://exemple.test/p?a=1', '', 'parcours')['lien']);
    }

    /** @return array<string, string> */
    private static function query(string $url, string $base): array
    {
        self::assertStringStartsWith($base.'?', $url);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return $query;
    }
}
