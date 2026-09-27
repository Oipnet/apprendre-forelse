<?php

namespace App\Tests\Content\Author;

use App\Content\Author\Scaffold\DockerScaffolder;
use App\Content\Author\Scaffold\ExerciseScaffolders;
use App\Content\Author\Scaffold\LaravelScaffolder;
use App\Content\Author\Scaffold\SymfonyScaffolder;
use App\Content\ContentException;
use App\Content\Framework\FrameworkRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Les squelettes de l'atelier : un par framework, et aucun pour un framework que l'atelier ne sait pas échafauder. */
final class ExerciseScaffolderTest extends KernelTestCase
{
    private const string ENTETE = "id: essai\ntitle: Essai\nconcepts: []\nxp: 200\n";

    public function testChaqueFrameworkRangeSesTestsAuBonEndroit(): void
    {
        $this->assertSame(
            ['exercise.yaml', 'instructions.md', 'starter/src/Controller/MonControleur.php', 'solution/src/Controller/MonControleur.php', 'tests/Exercice/MonTest.php'],
            array_keys((new SymfonyScaffolder())->files(self::ENTETE, 'Essai', false)),
        );
        $pratique = (new SymfonyScaffolder())->files(self::ENTETE, 'Essai', true);
        $this->assertStringContainsString('namespace App\\Tests\\Pratique;', $pratique['tests/Pratique/MonTest.php']);

        $laravel = (new LaravelScaffolder())->files(self::ENTETE, 'Essai', true);
        $this->assertStringContainsString('namespace Tests\\Pratique;', $laravel['tests/Pratique/MonTest.php']);
        $this->assertStringContainsString("namespace App\\Http\\Controllers;\n", $laravel['starter/app/Http/Controllers/MonControleur.php']);
        $this->assertArrayHasKey('tests/Formation/MonTest.php', (new LaravelScaffolder())->files(self::ENTETE, 'Essai', false));

        $docker = (new DockerScaffolder())->files(self::ENTETE, 'Essai', true);
        $this->assertArrayHasKey('tests/Docker/MonTest.php', $docker, 'Les tests Docker restent dans tests/Docker, Pratique comprise.');
        $this->assertStringStartsWith(self::ENTETE."\nopen: Dockerfile\n", $docker['exercise.yaml']);
    }

    public function testLEnteteEstRepriseTelleQuelle(): void
    {
        $yaml = (new SymfonyScaffolder())->files(self::ENTETE, 'Essai', false)['exercise.yaml'];

        $this->assertStringStartsWith(self::ENTETE."\nopen: src/Controller/MonControleur.php\npreview: /\n", $yaml);
        $this->assertStringEndsWith("hints:\n  - Premier indice.\n", $yaml);
    }

    public function testUnFrameworkSansSqueletteEstRefuse(): void
    {
        $scaffolders = self::getContainer()->get(ExerciseScaffolders::class);
        $frameworks = new FrameworkRegistry();

        foreach (['symfony', 'laravel', 'docker'] as $id) {
            $this->assertTrue($scaffolders->has($frameworks->get($id)), $id);
        }
        $this->assertFalse($scaffolders->has($frameworks->get('nuxt')));
        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('exercice Nuxt');
        $scaffolders->get($frameworks->get('nuxt'));
    }
}
