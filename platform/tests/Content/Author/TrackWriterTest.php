<?php

namespace App\Tests\Content\Author;

use App\Content\Author\TrackWriter;
use App\Content\ContentException;
use App\Content\Track;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Process\PhpProcess;

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

    /** Verrous posés dans un dossier du test (FlockStore, le magasin de LOCK_DSN=flock). */
    private function writer(): TrackWriter
    {
        return new TrackWriter(new LockFactory(new FlockStore($this->tmp.'/verrous')));
    }

    private function yaml(): string
    {
        return (string) file_get_contents($this->tmp.'/track.yaml');
    }

    public function testAjouteALaFinDuChapitreEtGardeLesCommentaires(): void
    {
        $this->writer()->ajouterExercice($this->track(), 'c1', '99-neuf');

        $this->assertStringContainsString("      - 02-deux\n      - 99-neuf\n", $this->yaml());
        $this->assertStringContainsString('# Deuxième chapitre', $this->yaml(), 'Le fichier garde ses commentaires.');
    }

    public function testAjouteDansLeBonChapitre(): void
    {
        $this->writer()->ajouterExercice($this->track(), 'c2', '99-neuf');

        $this->assertStringContainsString("      - 03-trois\n      - 99-neuf", $this->yaml());
    }

    public function testUnExerciceDejaInscritNEstPasAjouteDeuxFois(): void
    {
        $writer = $this->writer();
        $writer->ajouterExercice($this->track(), 'c1', '99-neuf');
        $writer->ajouterExercice($this->track(), 'c1', '99-neuf');

        $this->assertSame(1, substr_count($this->yaml(), '99-neuf'));
    }

    public function testUnChapitreInconnuEstSignale(): void
    {
        $this->expectExceptionMessageMatches('/Chapitre « c9 » introuvable/');

        $this->writer()->ajouterExercice($this->track(), 'c9', '99-neuf');
    }

    public function testUnSecondEcrivainAttendQueLePremierAitFini(): void
    {
        (new Filesystem())->mkdir($this->tmp.'/verrous');
        // Un autre auteur, dans un autre processus, tient le verrou de ce track.yaml pendant une seconde.
        $autre = new PhpProcess(sprintf(<<<'PHP'
            <?php
            require %s;
            $verrou = (new Symfony\Component\Lock\LockFactory(new Symfony\Component\Lock\Store\FlockStore(%s)))
                ->createLock(App\Content\Author\TrackWriter::lockKey(%s));
            $verrou->acquire(true);
            echo "pris\n";
            usleep(1_000_000);
            $verrou->release();
            PHP, var_export(\dirname(__DIR__, 3).'/vendor/autoload.php', true), var_export($this->tmp.'/verrous', true), var_export($this->tmp.'/track.yaml', true)));
        $autre->start();
        $autre->waitUntil(static fn (string $type, string $sortie) => str_contains($sortie, 'pris'));

        $debut = microtime(true);
        $this->writer()->ajouterExercice($this->track(), 'c1', '99-neuf');
        $attente = microtime(true) - $debut;
        $autre->wait();

        $this->assertTrue($autre->isSuccessful(), $autre->getErrorOutput());
        $this->assertGreaterThan(0.5, $attente, 'L\'écriture a attendu que l\'autre auteur libère le verrou.');
        $this->assertStringContainsString("      - 02-deux\n      - 99-neuf\n", $this->yaml());
    }
}
