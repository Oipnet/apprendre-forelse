<?php

namespace App\Tests\Content;

use App\Content\RepositoryUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Une adresse de dépôt peut porter un jeton : elle ne s'affiche jamais telle quelle.
 */
final class RepositoryUrlTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function adresses(): iterable
    {
        yield 'sans identifiants' => ['https://github.com/o/d.git', 'https://github.com/o/d.git'];
        yield 'un jeton seul' => ['https://ghp_secret@github.com/o/d.git', 'https://•••@github.com/o/d.git'];
        yield 'un couple' => ['https://moi:ghp_secret@github.com/o/d.git', 'https://•••@github.com/o/d.git'];
        yield 'un jeton qui ressemble au dépôt' => ['https://d.git@github.com/o/d.git', 'https://•••@github.com/o/d.git'];
        yield 'un jeton présent aussi dans le chemin' => ['https://ghp_secret@x.test/ghp_secret@b.git', 'https://•••@x.test/ghp_secret@b.git'];
        yield 'une adresse illisible' => ['pas une adresse', 'pas une adresse'];
    }

    #[DataProvider('adresses')]
    public function testUnJetonNeSortJamaisDeLAdresse(string $url, string $attendu): void
    {
        $affichable = RepositoryUrl::withoutCredentials($url);

        $this->assertSame($attendu, $affichable);
    }
}
