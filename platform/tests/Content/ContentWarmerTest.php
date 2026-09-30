<?php

namespace App\Tests\Content;

use App\Content\ContentRepository;
use App\Content\ContentWarmer;
use App\Content\EnvironmentRegistry;
use App\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Filesystem\Filesystem;

/** Le contenu est lu au démarrage (cache:clear) : la première page après un déploiement le trouve en cache. */
final class ContentWarmerTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    private string $tmp;
    private PhpFilesAdapter $cache;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/content-warmer-'.bin2hex(random_bytes(4));
        (new Filesystem())->mirror(self::ROOT.'/examples/packs/demo', $this->tmp.'/packs/demo');
        $this->cache = new PhpFilesAdapter('content', 0, $this->tmp.'/cache');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    private function repository(): ContentRepository
    {
        return new ContentRepository([$this->tmp.'/packs'], new EnvironmentRegistry(self::ROOT.'/environments'), new Version(self::ROOT.'/VERSION'), $this->cache);
    }

    public function testLeDemarrageRemplitLeCacheDuContenu(): void
    {
        (new ContentWarmer($this->repository()))->warmUp($this->tmp.'/cache');

        // Un YAML devenu illisible, mais inchangé pour le cache : la première requête ne le relit donc pas.
        $file = $this->tmp.'/packs/demo/tracks/decouverte/exercises/01-bonjour/exercise.yaml';
        $stat = stat($file);
        file_put_contents($file, str_repeat(':', $stat['size']));
        touch($file, $stat['mtime']);
        clearstatcache();

        $this->assertNotNull($this->repository()->findExercise('decouverte', '01-bonjour'));
    }

    public function testUnPackInvalideNArretePasLeDemarrage(): void
    {
        file_put_contents($this->tmp.'/packs/demo/pack.yaml', ':');

        $this->assertSame([], (new ContentWarmer($this->repository()))->warmUp($this->tmp.'/cache'));
    }
}
