<?php

namespace App\Tests\Export;

use App\Content\Chapter;
use App\Content\ContentRepository;
use App\Entity\User;
use App\Export\LessonPdf;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LessonPdfTest extends KernelTestCase
{
    public function testLaFicheDuPackDeDemoDevientUnPdfPersonnalise(): void
    {
        self::bootKernel();
        $content = static::getContainer()->get(ContentRepository::class);
        $track = $content->findTrack('decouverte');
        $chapter = $track->chapters[0];
        $user = (new User())->setEmail('ada@example.test')->setDisplayName('Ada');

        $summary = ['chapter' => $chapter, 'number' => 1, 'concepts' => ['Route', 'Contrôleur'], 'xp' => 100];
        $pdf = static::getContainer()->get(LessonPdf::class)->render($content->packs()['demo'], $track, $summary, $user, new \DateTimeImmutable('2026-09-12'));

        $this->assertStringStartsWith('%PDF-1.', $pdf);
        // dompdf compresse les flux : on compte les pages plutôt que de chercher le texte.
        $this->assertGreaterThanOrEqual(2, preg_match_all('~/Type\s*/Page\b(?!s)~', $pdf), 'Page de garde, puis la fiche.');
        $this->assertSame('decouverte-chapitre-01-bonjour-symfony.pdf', LessonPdf::filename($track, 1, $chapter));
    }

    public function testLeLivretEnchaineLesFichesDerriereUnSommaire(): void
    {
        self::bootKernel();
        $content = static::getContainer()->get(ContentRepository::class);
        $track = $content->findTrack('decouverte');
        $user = (new User())->setEmail('ada@example.test')->setDisplayName('Ada');
        $summary = ['chapter' => $track->chapters[0], 'number' => 1, 'concepts' => ['Route'], 'xp' => 100];

        $one = static::getContainer()->get(LessonPdf::class)->render($content->packs()['demo'], $track, $summary, $user);
        $booklet = static::getContainer()->get(LessonPdf::class)->renderBooklet($content->packs()['demo'], $track, [$summary, $summary], $user);

        $this->assertStringStartsWith('%PDF-1.', $booklet);
        $pages = static fn (string $pdf) => preg_match_all('~/Type\s*/Page\b(?!s)~', $pdf);
        $this->assertGreaterThan($pages($one), $pages($booklet), 'Page de garde, sommaire, puis chaque fiche sur ses propres pages.');
        $this->assertSame('decouverte-livret.pdf', LessonPdf::bookletFilename($track));
    }

    public function testLeNomDeFichierResteAscii(): void
    {
        self::bootKernel();
        $track = static::getContainer()->get(ContentRepository::class)->findTrack('decouverte');
        $chapter = new Chapter('les-cles', 'Les clés de la taverne : « à l\'étage »', []);

        $this->assertSame('decouverte-chapitre-04-les-cles-de-la-taverne-a-l-etage.pdf', LessonPdf::filename($track, 4, $chapter));
    }
}
