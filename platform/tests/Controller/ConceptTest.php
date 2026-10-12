<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les pages de notions. Pack de démo : « Contrôleur » est abordé par un exercice de parcours et par un exercice
 * de Pratique — c'est ce genre de rapprochement qu'aucun sommaire de parcours ne fait.
 */
final class ConceptTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    public function testLIndexListeLesNotionsAvecLeurNombreDExercices(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $crawler = $client->request('GET', '/notions');

        $this->assertResponseIsSuccessful();
        $this->assertFalse($client->getResponse()->headers->has('X-Robots-Tag'), 'L\'index s\'indexe.');
        $this->assertSame(['/notions/controleur'], $crawler->filter('.concepts-list a')->each(static fn ($node) => $node->attr('href')));
    }

    /**
     * Les thèmes que déclare un pack (notions/themes.yaml) rangent l'index ; un framework par onglet, et seulement
     * ceux qui ont une notion à ouvrir : Docker a un thème, mais aucune notion pratiquée.
     */
    public function testLIndexRangeLesNotionsParFrameworkEtParTheme(): void
    {
        $this->usePacks(__DIR__.'/../../../examples/packs', __DIR__.'/../Fixtures/packs/versions', __DIR__.'/../Fixtures/packs/notions');
        $client = static::createClient();
        $this->resetDatabase();

        $crawler = $client->request('GET', '/notions');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['concepts-symfony', 'concepts-laravel'], $crawler->filter('[role="tab"]')->each(static fn ($node) => $node->attr('aria-controls')));
        $this->assertCount(0, $crawler->filter('#concepts-docker'), 'Pas d\'onglet sans notion à ouvrir.');

        $symfony = $crawler->filter('#concepts-symfony');
        $this->assertSame('Les bases', $symfony->filter('.concepts-theme h3')->text());
        $this->assertSame(['/notions/controleur'], $symfony->filter('.concepts-list a')->each(static fn ($node) => $node->attr('href')));
        $this->assertStringContainsString('Bientôt', $symfony->filter('[data-concepts-name="Route"]')->text(), 'Une notion rangée sans page s\'annonce, sans lien.');

        $laravel = $crawler->filter('#concepts-laravel');
        $this->assertCount(0, $laravel->filter('.concepts-theme h3'), 'Sans thèmes, une seule liste sans titre.');
        $this->assertSame(['/notions/files-d-attente'], $laravel->filter('.concepts-list a')->each(static fn ($node) => $node->attr('href')));
        $this->assertStringContainsString('2 exercices', $laravel->filter('.concepts-list a')->text());
    }

    public function testUneNotionRassembleParcoursEtPratique(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $crawler = $client->request('GET', '/notions/controleur');

        $this->assertResponseIsSuccessful();
        $this->assertSame('Contrôleur', $crawler->filter('h1')->text());
        $links = $crawler->filter('.concept-exercises a')->each(static fn ($node) => $node->attr('href'));
        $this->assertContains('/parcours/decouverte/01-bonjour', $links);
        $this->assertContains('/pratique/exemple-map-request-header', $links);
        // La page mène aussi au sommaire du chapitre de l'exercice, pas seulement à l'exercice.
        $this->assertContains('/parcours/decouverte/chapitre/bonjour/sommaire', $links);
    }

    /** Une notion vue sur un seul exercice ne relie rien : elle reste une pastille, sans page à elle. */
    public function testUneNotionVueUneSeuleFoisNAPasDePage(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/notions/parametre-de-route');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUnSlugInconnuEstIntrouvable(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/notions/nexiste-pas');

        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * Le title d'un exercice mène par la notion : c'est ce qu'on cherche dans un moteur. « Bonjour Symfony »,
     * lui, ne répond à aucune requête — il ferme le titre, où il distingue deux exercices d'une même notion.
     */
    public function testLeTitleDUnExerciceCommenceParSesNotions(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $crawler = $client->request('GET', '/parcours/decouverte/01-bonjour');

        $this->assertSame('Route, Contrôleur en Symfony – exercice : Bonjour Symfony', $crawler->filter('title')->text());
    }

    /** Le sommaire d'un chapitre aussi : ses notions d'abord, son titre narratif ensuite. */
    public function testLeTitleDUnChapitreCommenceParSesNotions(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $crawler = $client->request('GET', '/parcours/decouverte/chapitre/bonjour/sommaire');

        $this->assertStringStartsWith('Route, Contrôleur en Symfony – ', $crawler->filter('title')->text());
    }

    public function testLaPageDUnExerciceNeLieQueLesNotionsQuiOntUnePage(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $crawler = $client->request('GET', '/parcours/decouverte/01-bonjour');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['/notions/controleur'], $crawler->filter('.exercise-concepts a')->each(static fn ($node) => $node->attr('href')));
        $this->assertSame(['Route'], $crawler->filter('.exercise-concepts span.tag')->each(static fn ($node) => $node->text()));
    }

    public function testLaPageDUnExerciceDePratiqueLieSesNotions(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $crawler = $client->request('GET', '/pratique/exemple-map-request-header');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['/notions/controleur'], $crawler->filter('.exercise-concepts a')->each(static fn ($node) => $node->attr('href')));
        $this->assertSame(['HttpKernel'], $crawler->filter('.exercise-concepts span.tag')->each(static fn ($node) => $node->text()));
    }

    /** Sans intro écrite, une notion n'est qu'une liste de liens : sa page se suit, mais ne s'indexe pas. */
    public function testUneNotionSansIntroNeSIndexePas(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $crawler = $client->request('GET', '/notions/controleur');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('X-Robots-Tag', 'noindex, follow');
        $this->assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        $this->assertCount(0, $crawler->filter('.concept-intro'));
    }

    /** Une intro écrite dans le pack (notions/controleur.md) ouvre la page, fait sa description et son titre, et la rend indexable. */
    public function testUneNotionAvecIntroSAfficheEtSIndexe(): void
    {
        $this->usePacks(__DIR__.'/../../../examples/packs', __DIR__.'/../Fixtures/packs/pratique');
        $client = static::createClient();
        $this->resetDatabase();

        $crawler = $client->request('GET', '/notions/controleur');

        $this->assertResponseIsSuccessful();
        $this->assertFalse($client->getResponse()->headers->has('X-Robots-Tag'));
        $this->assertStringContainsString('<code>Request</code>', $crawler->filter('.concept-intro')->html(), 'L\'intro est du Markdown, rendu comme tel.');
        $this->assertStringStartsWith('Un contrôleur reçoit la Request', (string) $crawler->filter('meta[name="description"]')->attr('content'));
        $this->assertSame('Contrôleur Symfony : de la requête à la réponse', $crawler->filter('h1')->text(), 'Le titre de l\'en-tête remplace l\'étiquette.');
        $this->assertStringStartsWith('Contrôleur Symfony : de la requête à la réponse', $crawler->filter('title')->text());

        $client->request('GET', '/sitemap.xml');
        $this->assertStringContainsString('<loc>http://localhost/notions/controleur</loc>', (string) $client->getResponse()->getContent());
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }
}
