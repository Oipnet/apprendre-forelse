<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Import de la progression d'invité (/api/progress/import) : seulement ce que le compte peut voir et ouvrir. */
final class ProgressImportTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }

    public function testUnParcoursEnPreparationNeSOuvrePasParLImport(): void
    {
        // « atelier-secret » est réservé aux administrateurs (visibility: admin).
        $this->usePacks(__DIR__.'/../Fixtures/packs/cohortes');
        $client = static::createClient();
        $this->resetDatabase();
        $client->loginUser($this->createUser());

        $result = $this->json($client, 'POST', '/api/progress/import', [
            ['trackId' => 'atelier-secret', 'exerciseId' => 'e1', 'files' => [], 'completed' => true],
            ['trackId' => 'symfony-bases', 'exerciseId' => 'e1', 'files' => [], 'completed' => false],
        ]);

        $this->assertSame(['imported' => 1], $result, 'Seul l\'exercice du parcours public est repris.');
        $client->request('GET', '/parcours/atelier-secret');
        $this->assertResponseStatusCodeSame(404, 'Le parcours en préparation reste invisible.');
    }

    public function testUneListeTropLongueEstRefusee(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $client->loginUser($this->createUser());

        $items = array_fill(0, 201, ['trackId' => 'decouverte', 'exerciseId' => '01-bonjour', 'files' => []]);
        $this->json($client, 'POST', '/api/progress/import', $items);

        $this->assertResponseStatusCodeSame(422);
    }
}
