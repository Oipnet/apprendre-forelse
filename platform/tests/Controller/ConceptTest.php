<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les pages de notions. Pack de démo : « Contrôleur » est abordé par un exercice de parcours et par un exercice
 * de Pratique — c'est ce genre de rapprochement qu'aucun sommaire de parcours ne fait.
 */
final class ConceptTest extends WebTestCase
{
    use DatabaseTrait;

    public function testLIndexListeLesNotionsAvecLeurNombreDExercices(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $crawler = $client->request('GET', '/notions');

        $this->assertResponseIsSuccessful();
        $this->assertFalse($client->getResponse()->headers->has('X-Robots-Tag'), 'L\'index s\'indexe.');
        $this->assertSame(['/notions/controleur'], $crawler->filter('.concepts-list a')->each(static fn ($node) => $node->attr('href')));
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
}
