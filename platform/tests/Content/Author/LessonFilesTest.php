<?php

namespace App\Tests\Content\Author;

use App\Content\Author\LessonFiles;
use App\Content\Author\PackWritability;
use App\Content\ContentException;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** La fiche de cours d'un chapitre, écrite et retirée dans un pack temporaire. */
final class LessonFilesTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../../..';
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/lesson-files-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        foreach ([
            'pack.yaml' => "id: p\ntitle: P\ntracks: [t]",
            'tracks/t/track.yaml' => "id: t\ntitle: La taverne\ndescription: Un parcours.\nenvironment: symfony-8\nchapters:\n  - {id: c1, title: Premiers pas, exercises: [e1]}",
            'tracks/t/exercises/e1/exercise.yaml' => "id: e1\ntitle: La route\nconcepts: [Route]\neditable: [src/Controller/MenuController.php]\nobjectives: [{test: testA, label: A}]",
            'tracks/t/exercises/e1/instructions.md' => "Gorm veut une page.\n",
        ] as $path => $content) {
            $filesystem->dumpFile($this->tmp.'/p/'.$path, $content);
        }
    }

    protected function tearDown(): void
    {
        @chmod($this->tmp.'/p/tracks/t', 0o755);
        (new Filesystem())->remove($this->tmp);
    }

    private function content(): ContentRepository
    {
        return new ContentRepository([$this->tmp], new EnvironmentRegistry(self::ROOT.'/environments'), new Version(self::ROOT.'/VERSION'));
    }

    private function files(ContentRepository $content, ?PackWritability $writability = null): LessonFiles
    {
        return new LessonFiles($content, new Filesystem(), new LockFactory(new InMemoryStore()), $writability ?? new PackWritability());
    }

    public function testEcrireNEcrasePasSansForce(): void
    {
        $content = $this->content();
        $track = $content->findTrack('t');
        $files = $this->files($content);

        $chemin = $files->ecrire($track, $track->chapters[0], "## Première version\n");
        $this->assertSame($this->tmp.'/p/tracks/t/chapters/c1/lesson.md', $chemin);
        $this->assertTrue($content->findTrack('t')->chapters[0]->hasLesson(), 'Le pack est relu après écriture.');

        try {
            $files->ecrire($track, $track->chapters[0], "## Deuxième version\n");
            $this->fail('Une fiche existante ne doit pas être écrasée sans --force.');
        } catch (ContentException $e) {
            $this->assertStringContainsString('--force', $e->getMessage());
        }
        $this->assertSame("## Première version\n", file_get_contents($chemin));

        $files->ecrire($track, $track->chapters[0], "## Deuxième version\n", force: true);
        $this->assertSame("## Deuxième version\n", file_get_contents($chemin));
    }

    public function testSupprimerRetireLaFicheEtSonDossier(): void
    {
        $content = $this->content();
        $track = $content->findTrack('t');
        $files = $this->files($content);
        $chemin = $files->ecrire($track, $track->chapters[0], "## Fiche\n");

        $files->supprimer($track, $track->chapters[0]);

        $this->assertFileDoesNotExist($chemin);
        $this->assertDirectoryDoesNotExist(\dirname($chemin), 'Pas de dossier de chapitre vide.');
        $this->assertFalse($content->findTrack('t')->chapters[0]->hasLesson());
    }

    public function testUnParcoursEnLectureSeuleNEstPasEcrit(): void
    {
        $content = $this->content();
        $track = $content->findTrack('t');
        // Monté en lecture seule, comme en production (sous root, un chmod ne suffirait pas à le reproduire).
        $lectureSeule = new PackWritability(static fn (string $dossier) => $dossier !== $track->directory);

        try {
            $this->files($content, $lectureSeule)->ecrire($track, $track->chapters[0], "## Fiche\n");
            $this->fail('Un parcours en lecture seule ne doit pas être écrit.');
        } catch (ContentException $e) {
            $this->assertSame(sprintf('Le parcours « t » est en lecture seule (%s).', $track->directory), $e->getMessage());
        }
        $this->assertFileDoesNotExist($track->directory.'/chapters/c1/lesson.md');

        $this->expectException(ContentException::class);
        $this->files($content, $lectureSeule)->supprimer($track, $track->chapters[0]);
    }
}
