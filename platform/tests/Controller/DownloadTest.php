<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use App\Tests\PaymentTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La route /telechargements sert les archives déposées dans DOWNLOADS_DIR : aux comptes connectés, et pour un fichier
 * qu'un parcours déclare (clé « downloads » de track.yaml), à ceux qui ont accès à tout ce parcours.
 */
final class DownloadTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;
    use PaymentTrait;

    private string $dossier;
    private string $fichier;

    protected function setUp(): void
    {
        $this->dossier = \dirname(__DIR__, 2).'/var/downloads';
        if (!is_dir($this->dossier)) {
            mkdir($this->dossier, 0777, true);
        }
        $this->fichier = $this->dossier.'/boutique-lacombe.zip';
        foreach (['boutique-lacombe.zip', 'supports-payant.zip', 'secret.zip'] as $nom) {
            file_put_contents($this->dossier.'/'.$nom, "PK\x03\x04-contenu-de-test");
        }
    }

    protected function tearDown(): void
    {
        foreach (['boutique-lacombe.zip', 'supports-payant.zip', 'secret.zip'] as $nom) {
            if (is_file($this->dossier.'/'.$nom)) {
                unlink($this->dossier.'/'.$nom);
            }
        }
        $this->restorePacks();
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
        $disposition = (string) $client->getResponse()->headers->get('Content-Disposition');
        $this->assertStringContainsString('boutique-lacombe.zip', $disposition);
    }

    public function testUnFichierAbsentDonneUne404(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $client->loginUser($this->createUser());
        $client->request('GET', '/telechargements/inexistant.zip');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testLeFichierDUnParcoursPayantDemandeLAccesAuParcours(): void
    {
        $this->usePaidPack();
        $client = static::createClient();
        $this->resetDatabase();
        $this->setPrice('payant', 7900);
        $client->loginUser($this->createUser());

        $client->request('GET', '/telechargements/supports-payant.zip');
        $this->assertResponseStatusCodeSame(403, 'Un compte sans le parcours n\'a pas ses fichiers.');

        // Un fichier qu'aucun parcours ne déclare reste ouvert à tout compte connecté.
        $client->request('GET', '/telechargements/boutique-lacombe.zip');
        $this->assertResponseIsSuccessful();
    }

    public function testLeFichierDUnParcoursPayantEstServiAQuiLAAchete(): void
    {
        $this->usePaidPack();
        $client = static::createClient();
        $this->resetDatabase();
        $this->setPrice('payant', 7900);
        $ada = $this->createUser();
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $entityManager->persist(\App\Entity\TrackAccess::gift($ada, 'payant', new \DateTimeImmutable('-1 day')));
        $entityManager->flush();
        $client->loginUser($ada);

        $client->request('GET', '/telechargements/supports-payant.zip');
        $this->assertResponseIsSuccessful();
    }

    public function testLeFichierDUnParcoursEnPreparationNExistePas(): void
    {
        $this->usePacks(__DIR__.'/../Fixtures/packs/cohortes');
        $client = static::createClient();
        $this->resetDatabase();
        $client->loginUser($this->createUser());

        $client->request('GET', '/telechargements/secret.zip');
        $this->assertResponseStatusCodeSame(404);
    }
}
