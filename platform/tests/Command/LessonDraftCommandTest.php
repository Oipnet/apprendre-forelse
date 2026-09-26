<?php

namespace App\Tests\Command;

use App\Ai\ModelClient;
use App\Command\ContentLessonDraftCommand;
use App\Content\Author\LessonDrafter;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/** content:lesson-draft : écrit la fiche de cours d'un chapitre dans un pack (temporaire), squelette ou brouillon. */
final class LessonDraftCommandTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/lesson-draft-command-'.bin2hex(random_bytes(4));
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
        (new Filesystem())->remove($this->tmp);
    }

    private function tester(?MockHttpClient $client = null): CommandTester
    {
        $content = new ContentRepository([$this->tmp], new EnvironmentRegistry(self::ROOT.'/environments'), new Version(self::ROOT.'/VERSION'));
        $drafter = new LessonDrafter($content, new ModelClient($client ?? new MockHttpClient(), 'cle-de-test', 'claude-sonnet-5'), new EnvironmentRegistry(self::ROOT.'/environments'));

        return new CommandTester(new Command(null, new ContentLessonDraftCommand($content, $drafter)));
    }

    private function lesson(): string
    {
        return $this->tmp.'/p/tracks/t/chapters/c1/lesson.md';
    }

    public function testEcritLeSqueletteDuChapitre(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['cible' => 't/c1']), $tester->getDisplay());
        $this->assertStringContainsString('Squelette écrit', $tester->getDisplay(true));
        $this->assertStringContainsString("## Ce que vous avez appris\n\n- **Route** <!-- e1 -->", (string) file_get_contents($this->lesson()));
    }

    public function testNEcrasePasUneFicheSansForce(): void
    {
        (new Filesystem())->dumpFile($this->lesson(), "## Ma fiche\n");

        $refus = $this->tester();
        $this->assertSame(Command::FAILURE, $refus->execute(['cible' => 't/c1']));
        $this->assertStringContainsString('--force', $refus->getDisplay(true));
        $this->assertSame("## Ma fiche\n", file_get_contents($this->lesson()));

        $this->assertSame(Command::SUCCESS, $this->tester()->execute(['cible' => 't/c1', '--force' => true]));
        $this->assertStringContainsString('## Ce que vous avez appris', (string) file_get_contents($this->lesson()));
    }

    public function testDemandeUnBrouillonAuModeleAvecIa(): void
    {
        $client = new MockHttpClient(new JsonMockResponse(['content' => [
            ['type' => 'tool_use', 'name' => 'ecrire_fiche', 'input' => ['fiche' => "# Premiers pas\n\n## Ce que vous avez appris\n\n- Une route.\n"]],
        ]]));
        $tester = $this->tester($client);

        $this->assertSame(Command::SUCCESS, $tester->execute(['cible' => 't/c1', '--ia' => true]), $tester->getDisplay());
        $this->assertStringContainsString('Brouillon écrit', $tester->getDisplay(true));
        $this->assertSame(1, $client->getRequestsCount());
        $this->assertSame("## Ce que vous avez appris\n\n- Une route.\n", file_get_contents($this->lesson()));
    }

    public function testUnChapitreInconnuEstSignaleAvecUnExemple(): void
    {
        foreach (['t/nulle-part', 'inconnu/c1', 't'] as $cible) {
            $tester = $this->tester();
            $this->assertSame(Command::FAILURE, $tester->execute(['cible' => $cible]), $cible);
            // Le bloc d'erreur est coupé à la largeur du terminal : on compare le texte, pas la mise en page.
            $this->assertStringContainsString('par exemple t/c1.', (string) preg_replace('/\s+/', ' ', $tester->getDisplay(true)));
        }
        $this->assertFileDoesNotExist($this->lesson());
    }
}
