<?php

namespace App\Tests\Content;

use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Le contenu chargé est gardé entre les requêtes (une instance de ContentRepository par requête), avec l'adaptateur
 * de la production (fichiers PHP), et périmé dès qu'un fichier lu change. Travaille sur une copie du pack de démo.
 */
final class ContentRepositoryCacheTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    private string $tmp;
    private string $pack;
    private PhpFilesAdapter $cache;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/content-cache-'.bin2hex(random_bytes(4));
        $this->pack = $this->tmp.'/packs/demo';
        (new Filesystem())->mirror(self::ROOT.'/examples/packs/demo', $this->pack);
        $this->cache = new PhpFilesAdapter('content', 0, $this->tmp.'/cache');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    /** Une requête : une instance neuve, le même cache. */
    private function request(): ContentRepository
    {
        return new ContentRepository([$this->tmp.'/packs'], new EnvironmentRegistry(self::ROOT.'/environments'), new Version(self::ROOT.'/VERSION'), $this->cache);
    }

    /** Réécrit un fichier sans que sa date ni sa taille ne changent : invisible pour le cache, qui ne le relit donc pas. */
    private function rewriteUnnoticed(string $file, string $content): void
    {
        $stat = stat($file);
        $this->assertSame($stat['size'], \strlen($content), 'Même taille, pour passer inaperçu.');
        file_put_contents($file, $content);
        touch($file, $stat['mtime']);
        clearstatcache();
    }

    /** Réécrit un fichier et avance sa date : comme un pack redéposé. */
    private function rewrite(string $file, string $content): void
    {
        file_put_contents($file, $content);
        touch($file, time() + 10);
        clearstatcache();
    }

    private function exerciseFile(): string
    {
        return $this->pack.'/tracks/decouverte/exercises/01-bonjour/exercise.yaml';
    }

    public function testUnCacheChaudNeRelitAucunYaml(): void
    {
        $title = $this->request()->findExercise('decouverte', '01-bonjour')?->title;
        $this->assertNotNull($title);

        // Des YAML devenus illisibles, mais inchangés pour le cache : s'il les relisait, le chargement échouerait.
        foreach ([$this->exerciseFile(), $this->pack.'/pack.yaml', $this->pack.'/tracks/decouverte/track.yaml'] as $file) {
            $this->rewriteUnnoticed($file, str_repeat(':', filesize($file)));
        }

        $this->assertSame($title, $this->request()->findExercise('decouverte', '01-bonjour')?->title);
    }

    public function testUnFichierModifieSeVoitALaRequeteSuivante(): void
    {
        $this->request()->tracks();
        $yaml = (string) file_get_contents($this->exerciseFile());
        $this->rewrite($this->exerciseFile(), (string) preg_replace('/^title: .*$/m', 'title: Titre redéposé', $yaml));

        $this->assertSame('Titre redéposé', $this->request()->findExercise('decouverte', '01-bonjour')?->title);
    }

    public function testUnExerciceDePratiqueAjouteApparait(): void
    {
        $this->assertArrayNotHasKey('copie', $this->request()->practices());

        $filesystem = new Filesystem();
        $source = glob($this->pack.'/practice/*', \GLOB_ONLYDIR)[0];
        $filesystem->mirror($source, $this->pack.'/practice/copie');
        $yaml = (string) file_get_contents($this->pack.'/practice/copie/exercise.yaml');
        file_put_contents($this->pack.'/practice/copie/exercise.yaml', (string) preg_replace('/^id: .*$/m', 'id: copie', $yaml));
        // Le dossier practice/ a changé : sa date avance (dans le test, pour ne pas dépendre de la seconde en cours).
        touch($this->pack.'/practice', time() + 10);
        clearstatcache();

        $this->assertArrayHasKey('copie', $this->request()->practices());
    }

    public function testUneFicheDeChapitreCreeeApparait(): void
    {
        // Le pack de démo en a une : on part d'un chapitre sans fiche, ni dossier chapters/.
        $filesystem = new Filesystem();
        $filesystem->remove($this->pack.'/tracks/decouverte/chapters');
        $track = $this->request()->findTrack('decouverte');
        $this->assertNotNull($track);
        $this->assertNull($track->chapters[0]->lesson);

        $filesystem->dumpFile($this->pack.'/tracks/decouverte/chapters/'.$track->chapters[0]->id.'/lesson.md', 'La fiche.');
        // Premier dossier créé sur le chemin : celui dont la date compte.
        touch($this->pack.'/tracks/decouverte', time() + 10);
        clearstatcache();

        $this->assertSame('La fiche.', $this->request()->findTrack('decouverte')?->chapters[0]->lesson);
    }

    public function testResetVideLeCachePourLAtelier(): void
    {
        $content = $this->request();
        $content->tracks();
        // L'atelier écrit dans la même seconde : la date seule ne suffirait pas à le voir.
        $yaml = (string) file_get_contents($this->exerciseFile());
        preg_match('/^title: (.*)$/m', $yaml, $title);
        $written = substr_replace('Écrit', str_repeat('.', \strlen($title[1]) - \strlen('Écrit')), \strlen('Écrit'), 0);
        $this->rewriteUnnoticed($this->exerciseFile(), str_replace('title: '.$title[1], 'title: '.$written, $yaml));
        $content->reset();

        $this->assertStringStartsWith('Écrit', (string) $this->request()->findExercise('decouverte', '01-bonjour')?->title);
    }
}
