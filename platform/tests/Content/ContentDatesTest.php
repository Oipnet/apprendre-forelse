<?php

namespace App\Tests\Content;

use App\Content\ContentDates;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;

/** Les dates du contenu : lues une fois sur le disque, puis servies par le cache. */
final class ContentDatesTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/content-dates-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        foreach ([
            'pack.yaml' => "id: p\ntitle: P\ntracks: [t]",
            'tracks/t/track.yaml' => "id: t\ntitle: La taverne\ndescription: Un parcours.\nenvironment: symfony-8\nchapters:\n  - {id: c1, title: Premiers pas, exercises: [e1]}",
            'tracks/t/exercises/e1/exercise.yaml' => "id: e1\ntitle: La route\nconcepts: [Route]\neditable: [src/Controller/MenuController.php]\nobjectives: [{test: testA, label: A}]",
            'tracks/t/exercises/e1/instructions.md' => "Gorm veut une page.\n",
            'practice/p1/exercise.yaml' => "id: p1\ntitle: Une nouveauté\nconcepts: [Route]\neditable: [src/Controller/MenuController.php]\nobjectives: [{test: testA, label: A}]\nenvironment: symfony-8\npublished: 2030-01-02\nsummary: Une phrase.",
            'practice/p1/instructions.md' => "Faites.\n",
        ] as $path => $content) {
            $filesystem->dumpFile($this->tmp.'/p/'.$path, $content);
        }
        touch($this->tmp.'/p/tracks/t/exercises/e1/instructions.md', strtotime('2026-01-15 12:00'));
        touch($this->tmp.'/p/tracks/t/exercises/e1/exercise.yaml', strtotime('2026-01-10 12:00'));
        touch($this->tmp.'/p/tracks/t/exercises/e1', strtotime('2026-01-01 12:00'));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    public function testLaDateEstCelleDuFichierLePlusRecent(): void
    {
        $dates = new ContentDates(new ArrayAdapter());

        $this->assertSame('2026-01-15', $dates->lastModified($this->tmp.'/p/tracks/t/exercises/e1'));
        $this->assertSame(strtotime('2026-01-15 12:00'), $dates->latestFileNow($this->tmp.'/p/tracks/t/exercises/e1')?->getTimestamp());
        $this->assertNull($dates->latestFileNow($this->tmp.'/nexiste-pas'));
    }

    public function testCacheChaudLeDisqueNEstPlusRelu(): void
    {
        $dates = new ContentDates(new ArrayAdapter());
        $dossier = $this->tmp.'/p/tracks/t/exercises/e1';
        $dates->lastModified($dossier);

        touch($dossier.'/instructions.md', strtotime('2026-03-01 12:00'));

        $this->assertSame('2026-01-15', $dates->lastModified($dossier), 'Servie par le cache.');
        $this->assertSame(strtotime('2026-03-01 12:00'), $dates->latestFileNow($dossier)?->getTimestamp(), 'L\'atelier lit la date du moment.');
    }

    public function testUnePratiqueNEstPasModifieeAvantSaPublication(): void
    {
        $content = new ContentRepository([$this->tmp], new EnvironmentRegistry(self::ROOT.'/environments'), new Version(self::ROOT.'/VERSION'));
        $practice = $content->findPractice('p1');
        $this->assertNotNull($practice);

        $this->assertSame('2030-01-02', (new ContentDates(new ArrayAdapter()))->practiceModified($practice));
    }
}
