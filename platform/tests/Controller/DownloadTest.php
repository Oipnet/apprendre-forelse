<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** La route /telechargements sert les archives déposées dans DOWNLOADS_DIR, aux seuls comptes connectés. */
final class DownloadTest extends WebTestCase
{
    use DatabaseTrait;

    private string $fichier;

    protected function setUp(): void
    {
        $dossier = \dirname(__DIR__, 2).'/var/downloads';
        if (!is_dir($dossier)) {
            mkdir($dossier, 0777, true);
        }
        $this->fichier = $dossier.'/boutique-lacombe.zip';
        file_put_contents($this->fichier, "PK\x03\x04-contenu-de-test");
    }

    protected function tearDown(): void
    {
        if (isset($this->fichier) && is_file($this->fichier)) {
            unlink($this->fichier);
        }
        parent::tearDown();
    }

    public function testUnVisiteurAnonymeEstRedirigeVersLaConnexion(): void
    {
        $client = static::createClient();
        $client->request('GET', '/telechargements/boutique-lacombe.zip');

        $this->assertResponseRedirects();
    }

    public function testUnCompteConnecteRecoitLArchive(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $client->loginUser($this->createUser());
        $client->request('GET', '/telechargements/boutique-lacombe.zip');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderContains('Content-Disposition', 'boutique-lacombe.zip');
    }

    public function testUnFichierAbsentDonneUne404(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $client->loginUser($this->createUser());
        $client->request('GET', '/telechargements/inexistant.zip');

        $this->assertResponseStatusCodeSame(404);
    }
}
