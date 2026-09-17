<?php

namespace App\Tests\Content\Author;

use App\Content\Author\TrackWriter;
use App\Content\ContentException;
use App\Content\Track;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class TrackWriterTest extends TestCase
{
    private const string TRACK_YAML = <<<'YAML'
        id: t
        title: T
        environment: symfony-8
        chapters:
          - id: c1
            title: C1
            exercises:
              - 01-un
              - 02-deux
          # Deuxième chapitre
          - id: c2
            title: C2
            exercises:
              - 03-trois
        YAML;

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/track-'.bin2hex(random_bytes(4));
        (new Filesystem())->dumpFile($this->tmp.'/track.yaml', self::TRACK_YAML."\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    private function track(): Track
    {
        return new Track('t', 'p', 'T', '', 'symfony-8', [], $this->tmp);
    }

    private function yaml(): string
    {
        return (string) file_get_contents($this->tmp.'/track.yaml');
    }

    public function testAjouteALaFinDuChapitreEtGardeLesCommentaires(): void
    {
        (new TrackWriter())->ajouterExercice($this->track(), 'c1', '99-neuf');

        $this->assertStringContainsString("      - 02-deux\n      - 99-neuf\n", $this->yaml());
        $this->assertStringContainsString('# Deuxième chapitre', $this->yaml(), 'Le fichier garde ses commentaires.');
    }

    public function testAjouteDansLeBonChapitre(): void
    {
        (new TrackWriter())->ajouterExercice($this->track(), 'c2', '99-neuf');

        $this->assertStringContainsString("      - 03-trois\n      - 99-neuf", $this->yaml());
    }

    public function testUnExerciceDejaInscritNEstPasAjouteDeuxFois(): void
    {
        $writer = new TrackWriter();
        $writer->ajouterExercice($this->track(), 'c1', '99-neuf');
        $writer->ajouterExercice($this->track(), 'c1', '99-neuf');

        $this->assertSame(1, substr_count($this->yaml(), '99-neuf'));
    }

    public function testUnChapitreInconnuEstSignale(): void
    {
        $this->expectExceptionMessageMatches('/Chapitre « c9 » introuvable/');

        (new TrackWriter())->ajouterExercice($this->track(), 'c9', '99-neuf');
    }
}
