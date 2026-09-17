<?php

namespace App\Tests\Controller;

use App\Ai\ModelClient;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class MentorApiTest extends WebTestCase
{
    use DatabaseTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    /** Remplace le modèle par un faux qui répond toujours `$entree` à l'outil `$outil`. */
    private function faireRepondre(string $outil, array $entree, string $cle = 'cle-de-test'): void
    {
        $http = new MockHttpClient(fn () => new JsonMockResponse(['content' => [['type' => 'tool_use', 'name' => $outil, 'input' => $entree]]]));
        static::getContainer()->set(ModelClient::class, new ModelClient($http, $cle, 'claude-sonnet-5'));
    }

    public function testUnInviteNAPasDeMentor(): void
    {
        $this->json($this->client, 'POST', '/api/mentor/decouverte/01-bonjour/review', ['files' => []]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testSansCleLeMentorEstIndisponible(): void
    {
        $this->client->loginUser($this->createUser());
        $this->json($this->client, 'POST', '/api/mentor/decouverte/01-bonjour/review', ['files' => []]);

        $this->assertResponseStatusCodeSame(503);
    }

    public function testLaRevueEstRendueEtConservee(): void
    {
        $this->faireRepondre('revue_de_code', ['summary' => 'Bien.', 'points' => [['file' => 'src/Controller/BonjourController.php', 'line' => 3, 'message' => 'Un point.', 'snippet' => null]]]);
        $this->client->loginUser($this->createUser());

        $revue = $this->json($this->client, 'POST', '/api/mentor/decouverte/01-bonjour/review', ['files' => ['src/Controller/BonjourController.php' => '<?php']]);

        $this->assertResponseIsSuccessful();
        $this->assertSame('Bien.', $revue['summary']);
        $this->assertSame(3, $revue['points'][0]['line']);

        $progress = $this->json($this->client, 'GET', '/api/progress/decouverte/01-bonjour');
        $this->assertSame($revue, $progress['review'], 'La revue est relue avec la progression, sans nouvel appel au modèle.');
    }

    public function testUneErreurEstExpliquee(): void
    {
        $this->faireRepondre('expliquer_erreur', ['cause' => 'La route manque.', 'piste' => 'Regardez l\'attribut.', 'file' => null]);
        $this->client->loginUser($this->createUser());

        $explication = $this->json($this->client, 'POST', '/api/mentor/decouverte/01-bonjour/explain', ['error' => 'No route found', 'source' => 'preview', 'files' => []]);

        $this->assertResponseIsSuccessful();
        $this->assertSame(['cause' => 'La route manque.', 'piste' => 'Regardez l\'attribut.', 'file' => null], $explication);
    }

    public function testUneSourceInconnueOuUneErreurVideSontRefusees(): void
    {
        $this->faireRepondre('expliquer_erreur', []);
        $this->client->loginUser($this->createUser());

        $this->json($this->client, 'POST', '/api/mentor/decouverte/01-bonjour/explain', ['error' => 'x', 'source' => 'telepathie']);
        $this->assertResponseStatusCodeSame(422);
        $this->json($this->client, 'POST', '/api/mentor/decouverte/01-bonjour/explain', ['error' => '', 'source' => 'tests']);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testUnModeleMuetDonneUne502(): void
    {
        $http = new MockHttpClient(fn () => new JsonMockResponse(['content' => [['type' => 'text', 'text' => 'Je préfère ne rien dire.']]]));
        static::getContainer()->set(ModelClient::class, new ModelClient($http, 'cle', 'claude-sonnet-5'));
        $this->client->loginUser($this->createUser());

        $this->json($this->client, 'POST', '/api/mentor/decouverte/01-bonjour/explain', ['error' => 'Erreur', 'source' => 'tests']);

        $this->assertResponseStatusCodeSame(502);
    }

    public function testUnExerciceInconnuDonne404(): void
    {
        $this->faireRepondre('revue_de_code', []);
        $this->client->loginUser($this->createUser());
        $this->json($this->client, 'POST', '/api/mentor/decouverte/inexistant/review', ['files' => []]);

        $this->assertResponseStatusCodeSame(404);
    }
}
