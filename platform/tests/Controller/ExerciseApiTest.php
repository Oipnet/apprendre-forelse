<?php

namespace App\Tests\Controller;

use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use App\Version;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExerciseApiTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    public function testUnExerciceLibreEstServiSansSolution(): void
    {
        $client = static::createClient();
        $payload = $this->json($client, 'GET', '/api/exercises/decouverte/01-bonjour');

        $this->assertResponseIsSuccessful();
        $this->assertSame('free', $payload['access']);
        $this->assertStringContainsString('TODO', $payload['files']['src/Controller/BonjourController.php']);
        $this->assertArrayHasKey('tests/BonjourTest.php', $payload['tests']);
        $this->assertArrayNotHasKey('solution', $payload, 'La solution ne doit jamais être envoyée hors développement.');
        $this->assertMatchesRegularExpression('#^http://localhost/envs/symfony-8\.zip(\?v=\d+)?$#', $payload['environment']['archiveUrl'], 'Versionnée par la date de construction, quand l\'archive existe.');
        $this->assertMatchesRegularExpression('#^http://localhost/envs/symfony-8\.completion\.json(\?v=\d+)?$#', $payload['environment']['completionIndexUrl']);
        if (is_file(__DIR__.'/../../public/envs/symfony-8.completion.json')) {
            $this->assertStringContainsString('?v='.filemtime(__DIR__.'/../../public/envs/symfony-8.completion.json'), $payload['environment']['completionIndexUrl'], 'Un index reconstruit change d\'URL : le cache du navigateur ne le masque pas.');
        }
        $this->assertSame('/parcours/decouverte/02-bonjour-prenom', $payload['next']['url']);
    }

    public function testUnExerciceAvecCompteEstRefuseAUnInvite(): void
    {
        $this->usePaidPack();
        try {
            $client = static::createClient();
            $this->json($client, 'GET', '/api/exercises/payant/e2');
            $this->assertResponseStatusCodeSame(401, 'Hors du premier chapitre, un compte est demandé.');
            $this->assertSame('free', $this->json($client, 'GET', '/api/exercises/payant/e1')['access'], 'Le premier chapitre est libre.');
        } finally {
            $this->restorePacks();
        }
    }

    public function testUnExerciceAvecComptePartDeLaSolutionPrecedente(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $client->loginUser($this->createUser());
        $payload = $this->json($client, 'GET', '/api/exercises/decouverte/02-bonjour-prenom');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString("#[Route('/bonjour'", $payload['files']['src/Controller/BonjourController.php']);
        $this->assertNull($payload['next']);
        $this->assertNull($payload['nextTrack'], 'Le parcours conseillé (symfony-pour-dev-php) n\'est pas installé ici : rien à proposer.');
    }

    public function testLeDernierExerciceConseilleLeParcoursSuivant(): void
    {
        $client = static::createClient();
        static::getContainer()->set(ContentRepository::class, new ContentRepository(
            [__DIR__.'/../../../examples/packs', __DIR__.'/../Fixtures/packs'],
            static::getContainer()->get(EnvironmentRegistry::class),
            new Version(__DIR__.'/../../../VERSION'),
        ));

        $payload = $this->json($client, 'GET', '/api/exercises/debut/e1');

        $this->assertResponseIsSuccessful();
        $this->assertNull($payload['next']);
        $this->assertSame(['id' => 'suite', 'title' => 'La suite', 'description' => 'Le parcours conseillé après « Début ».', 'url' => '/parcours/suite'], $payload['nextTrack']);
        $this->assertNull($this->json($client, 'GET', '/api/exercises/decouverte/01-bonjour')['nextTrack'], 'Pas de parcours conseillé tant qu\'il reste des exercices.');
    }

    public function testExerciceInconnu(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/exercises/decouverte/../../etc/passwd');

        $this->assertResponseStatusCodeSame(404);
    }
}
