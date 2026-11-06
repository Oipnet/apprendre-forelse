<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Un exercice à étapes (pack de test « etapes ») : ce que reçoit le playground, l'étape retenue, la solution d'une étape. */
final class StepProgressApiTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    private const string EXERCISE = '/api/progress/tdd/01-le-premier-bidon';
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->usePacks(__DIR__.'/../Fixtures/packs/etapes');
        $this->client = static::createClient();
        $this->resetDatabase();
        $this->client->loginUser($this->createUser());
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }

    public function testLePlaygroundRecoitLesEtapes(): void
    {
        $payload = $this->json($this->client, 'GET', '/api/exercises/tdd/01-le-premier-bidon');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['red', 'green', 'red', 'green', 'refactor'], array_column($payload['steps'], 'phase'));
        $this->assertSame(['tests/PaieDuLaitTest.php'], $payload['steps'][0]['editable']);
        $this->assertSame(['own-tests-fail', 'own-tests-reference'], array_column($payload['steps'][0]['objectives'], 'test'));
        $this->assertStringContainsString('Étape rouge-bidon-vide.', $payload['steps'][0]['instructionsHtml']);
        // Le code attendu d'une étape rouge : celui de l'étape verte suivante, sans les tests de l'apprenant.
        $this->assertSame(['src/PaieDuLait.php'], array_keys($payload['steps'][2]['reference']));
        $this->assertStringContainsString('return $litres * 50;', $payload['steps'][2]['reference']['src/PaieDuLait.php']);
        $this->assertNull($payload['steps'][1]['reference']);
        $this->assertSame(['prix-oublie'], $payload['steps'][4]['mutants']);
        $this->assertSame(['tests/PaieDuLaitTest.php', 'src/PaieDuLait.php'], $payload['editable']);
        $this->assertArrayNotHasKey('stepSolutions', $payload, 'Les solutions des étapes ne partent qu\'en développement.');
    }

    public function testLEtapeEstRetenueAvecLeBrouillon(): void
    {
        $this->json($this->client, 'PUT', self::EXERCISE, ['files' => ['src/PaieDuLait.php' => '<?php // brouillon'], 'step' => 2]);
        $this->assertResponseStatusCodeSame(204);
        $this->assertSame(2, $this->json($this->client, 'GET', self::EXERCISE)['step']);

        // Un brouillon sans étape (onglet resté ouvert sur un ancien playground) ne la remet pas à zéro.
        $this->json($this->client, 'PUT', self::EXERCISE, ['files' => []]);
        $this->assertSame(2, $this->json($this->client, 'GET', self::EXERCISE)['step']);

        // Au-delà de la dernière étape : la dernière.
        $this->json($this->client, 'PUT', self::EXERCISE, ['files' => [], 'step' => 50]);
        $this->assertSame(4, $this->json($this->client, 'GET', self::EXERCISE)['step']);
    }

    public function testLaSolutionDemandeeEstCelleDeLEtape(): void
    {
        $solution = $this->json($this->client, 'POST', self::EXERCISE.'/solution', ['step' => 1])['files'];

        $this->assertStringContainsString('return 0;', $solution['src/PaieDuLait.php']);
        $this->assertStringContainsString('testUnBidonVideNeRapporteRien', $solution['tests/PaieDuLaitTest.php'], 'Avec celles des étapes d\'avant.');
        $this->assertStringNotContainsString('testDixLitres', $solution['tests/PaieDuLaitTest.php']);

        $finale = $this->json($this->client, 'POST', self::EXERCISE.'/solution')['files'];
        $this->assertStringContainsString('PRIX_DU_LITRE', $finale['src/PaieDuLait.php'], 'Sans étape : celle de tout l\'exercice.');
    }
}
