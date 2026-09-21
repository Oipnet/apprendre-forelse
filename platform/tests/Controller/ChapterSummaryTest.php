<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le sommaire public d'un chapitre : le niveau qui manquait entre un parcours et ses exercices.
 * Pack de démo (chapitre « bonjour », deux exercices).
 */
final class ChapterSummaryTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    private const string PAGE = '/parcours/decouverte/chapitre/bonjour/sommaire';

    public function testLeSommaireSeLitSansCompteEtMeneAChaqueExercice(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $crawler = $client->request('GET', self::PAGE);

        $this->assertResponseIsSuccessful();
        $this->assertFalse($client->getResponse()->headers->has('X-Robots-Tag'), 'Le sommaire s\'indexe.');
        $this->assertSame('Bonjour Symfony', $crawler->filter('h1')->text());

        $links = $crawler->filter('.chapter-outline-list h3 a')->each(static fn ($node) => $node->attr('href'));
        $this->assertSame([
            '/parcours/decouverte/01-bonjour',
            '/parcours/decouverte/02-bonjour-prenom',
        ], $links, 'Chaque exercice du chapitre a son lien.');
    }

    public function testLeSommaireAnnonceLesNotionsSansDonnerLesObjectifs(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $crawler = $client->request('GET', self::PAGE);

        $concepts = $crawler->filter('.chapter-outline-list .tag')->each(static fn ($node) => $node->text());
        $this->assertSame(['Route', 'Contrôleur', 'Paramètre de route'], $concepts);
        // Ce que des tests vérifient ne se lit pas d'avance (voir exercise.yaml du pack de démo).
        $this->assertStringNotContainsString('La page /bonjour répond', (string) $client->getResponse()->getContent());
    }

    public function testUnChapitreInconnuEstIntrouvable(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/parcours/decouverte/chapitre/inconnu/sommaire');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testLaFicheDeCoursResteReserveeMalgreLeSommairePublic(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/parcours/decouverte/chapitre/bonjour');

        $this->assertResponseRedirects('/connexion');
    }

    /** Un parcours réservé aux administrateurs n'existe pas pour les autres : ses sommaires non plus. */
    public function testUnParcoursEnPreparationNAPasDeSommaire(): void
    {
        $this->usePacks(__DIR__.'/../Fixtures/packs/cohortes');
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/parcours/atelier-secret/chapitre/c1/sommaire');

        $this->assertResponseStatusCodeSame(404);
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }
}
