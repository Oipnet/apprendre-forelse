<?php

namespace App\Tests\Content\Author;

use App\Content\Author\LessonDrafter;
use App\Ai\ModelClient;
use App\Content\ContentException;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class LessonDrafterTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../../..';
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/lesson-draft-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        foreach ([
            'pack.yaml' => "id: p\ntitle: P\ntracks: [t]",
            'tracks/t/track.yaml' => "id: t\ntitle: La taverne\ndescription: Un parcours.\nenvironment: symfony-8\nchapters:\n  - {id: c1, title: Premiers pas, exercises: [e1, e2]}",
            'tracks/t/exercises/e1/exercise.yaml' => "id: e1\ntitle: La route\nconcepts: [Route, Contrôleur]\neditable: [src/Controller/MenuController.php]\nobjectives: [{test: testA, label: A}]\ndocs:\n  - {title: Le routage, url: 'https://symfony.com/doc/current/routing.html'}",
            'tracks/t/exercises/e1/instructions.md' => "Gorm veut une page.\n\n## Votre mission\n\n1. Déclarez une route.\n\n## Rappel\n\nUn contrôleur est une simple classe PHP.\n`AbstractController` offre `render()`.\n",
            'tracks/t/exercises/e1/solution/src/Controller/MenuController.php' => "<?php\n// solution e1\n",
            'tracks/t/exercises/e2/exercise.yaml' => "id: e2\ntitle: Le template\nconcepts: [Twig, Contrôleur]\neditable: [templates/menu.html.twig]\nobjectives: [{test: testA, label: A}]\ndocs:\n  - {title: Le routage, url: 'https://symfony.com/doc/current/routing.html'}\n  - {title: Twig, url: 'https://twig.symfony.com/doc/3.x/'}",
            'tracks/t/exercises/e2/instructions.md' => "Affichez la carte.\n\n## Rappel\n\nTwig échappe tout par défaut.\n",
        ] as $path => $content) {
            $filesystem->dumpFile($this->tmp.'/p/'.$path, $content);
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    private function content(): ContentRepository
    {
        return new ContentRepository([$this->tmp], new EnvironmentRegistry(self::ROOT.'/environments'), new Version(self::ROOT.'/VERSION'));
    }

    private function drafter(?MockHttpClient $client = null, string $cle = 'cle-de-test'): LessonDrafter
    {
        return new LessonDrafter($this->content(), new ModelClient($client ?? new MockHttpClient(), $cle, 'claude-sonnet-5'), new EnvironmentRegistry(self::ROOT.'/environments'));
    }

    public function testLeSqueletteAssembleConceptsRappelsEtLiens(): void
    {
        $content = $this->content();
        $track = $content->findTrack('t');
        $squelette = $this->drafter()->squelette($track, $track->chapters[0]);

        $this->assertStringStartsWith("## Ce que vous avez appris\n\n- **Route** <!-- e1 -->\n- **Contrôleur** <!-- e1, e2 -->\n- **Twig** <!-- e2 -->\n", $squelette);
        $this->assertStringContainsString("## Contrôleur\n\n<!-- Vu dans : e1, e2.", $squelette, 'Une section par concept, avec les exercices qui l\'abordent.');
        $this->assertStringContainsString("## Rappels vus dans les exercices\n", $squelette);
        $this->assertStringContainsString("### La route\n\nUn contrôleur est une simple classe PHP.\n`AbstractController` offre `render()`.", $squelette, 'La section « Rappel » des consignes est recopiée…');
        $this->assertStringNotContainsString('Votre mission', $squelette, '… sans le reste des consignes.');
        $this->assertStringContainsString("### Le template\n\nTwig échappe tout par défaut.", $squelette);
        $this->assertStringContainsString("## Les pièges\n\n- \n", $squelette);
        $this->assertStringEndsWith("## Pour aller plus loin\n\n- [Le routage](https://symfony.com/doc/current/routing.html)\n- [Twig](https://twig.symfony.com/doc/3.x/)\n", $squelette, 'Les liens sont dédupliqués.');
    }

    public function testLeBrouillonDonneAuModeleConsignesSolutionsEtLiens(): void
    {
        $requetes = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requetes) {
            $requetes[] = json_decode($options['body'], true);

            return new JsonMockResponse(['content' => [
                ['type' => 'tool_use', 'name' => 'ecrire_fiche', 'input' => ['fiche' => "# Premiers pas\n\n## Ce que vous avez appris\n\n- Une route.\n"]],
            ]]);
        });
        $content = $this->content();
        $track = $content->findTrack('t');

        $fiche = $this->drafter($client)->brouillon($track, $track->chapters[0]);

        $this->assertSame("## Ce que vous avez appris\n\n- Une route.\n", $fiche, 'Le titre de niveau 1 est retiré : le moteur affiche celui du chapitre.');
        $this->assertSame('ecrire_fiche', $requetes[0]['tool_choice']['name']);
        $message = $requetes[0]['messages'][0]['content'];
        $this->assertStringContainsString('Chapitre « Premiers pas » (2 exercices)', $message);
        $this->assertStringContainsString('Gorm veut une page.', $message, 'Les consignes sont fournies…');
        $this->assertStringContainsString('// solution e1', $message, '… et les solutions…');
        $this->assertStringContainsString('[Twig](https://twig.symfony.com/doc/3.x/)', $message, '… et les liens de documentation.');
        $this->assertStringContainsString('fiche de cours', $requetes[0]['system']);
    }

    public function testSansCleLeBrouillonEstIndisponible(): void
    {
        $content = $this->content();
        $track = $content->findTrack('t');

        $this->expectException(ContentException::class);
        $this->expectExceptionMessageMatches('/clé d\'API/');
        $this->drafter(cle: '')->brouillon($track, $track->chapters[0]);
    }

    public function testEcrireNEcrasePasSansForce(): void
    {
        $content = $this->content();
        $track = $content->findTrack('t');
        $drafter = new LessonDrafter($content, new ModelClient(new MockHttpClient(), 'cle-de-test', 'claude-sonnet-5'), new EnvironmentRegistry(self::ROOT.'/environments'));

        $chemin = $drafter->ecrire($track, $track->chapters[0], "## Première version\n");
        $this->assertSame($this->tmp.'/p/tracks/t/chapters/c1/lesson.md', $chemin);
        $this->assertTrue($content->findTrack('t')->chapters[0]->hasLesson(), 'Le pack est relu après écriture.');

        try {
            $drafter->ecrire($track, $track->chapters[0], "## Deuxième version\n");
            $this->fail('Une fiche existante ne doit pas être écrasée sans --force.');
        } catch (ContentException $e) {
            $this->assertStringContainsString('--force', $e->getMessage());
        }
        $this->assertSame("## Première version\n", file_get_contents($chemin));

        $drafter->ecrire($track, $track->chapters[0], "## Deuxième version\n", force: true);
        $this->assertSame("## Deuxième version\n", file_get_contents($chemin));
    }

    public function testRappel(): void
    {
        $this->assertNull(LessonDrafter::rappel("Pas de rappel.\n\n## Votre mission\n\n1. Faites."));
        $this->assertSame('Le texte.', LessonDrafter::rappel("## Rappel\n\nLe texte.\n"));
        $this->assertSame("Avant.\n\n### Détail\n\nAprès.", LessonDrafter::rappel("## Rappel\n\nAvant.\n\n### Détail\n\nAprès.\n\n## Suite\n\nNon."), 'Jusqu\'au prochain titre de niveau 2.');
        $this->assertNull(LessonDrafter::rappel("## Rappel\n\n\n## Suite\n"), 'Une section vide ne compte pas.');
    }
}
