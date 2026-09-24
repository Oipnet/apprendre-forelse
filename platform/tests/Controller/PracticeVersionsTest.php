<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le groupement des pages de nouveautés, qui n'est pas le même d'un framework à l'autre : une nouveauté Symfony
 * appartient à une version mineure qu'on attend (8.2), une nouveauté Laravel sort dans une mineure parmi vingt
 * (13.24, 13.26) et se range sous la majeure. Pack de test « versions » : deux mineures de Laravel 13, et deux
 * versions de Symfony avec un seul exercice chacune.
 */
final class PracticeVersionsTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->usePacks(__DIR__.'/../Fixtures/packs/versions');
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }

    /** Deux mineures de Laravel font une page « Laravel 13 » : c'est cette version-là qu'on cherche. */
    public function testLesMineuresDeLaravelSeRangentSousLaMajeure(): void
    {
        $crawler = $this->client->request('GET', '/pratique/nouveautes/laravel-13');

        $this->assertResponseIsSuccessful();
        $this->assertSame('Les nouveautés de Laravel 13', $crawler->filter('h1')->text());
        $this->assertSame(
            ['Laravel, une autre mineure', 'Laravel, une mineure'],
            $crawler->filter('.practice-list .title')->extract(['_text']),
        );
        $this->assertSelectorTextContains('.pr-scope', 'ne liste pas toutes les nouveautés de Laravel 13');
    }

    /** Sans intro écrite, la page dit ce que ses exercices font travailler : deux pages ne se ressemblent pas. */
    public function testSansIntroEcriteLaPageComposeSonTexte(): void
    {
        $crawler = $this->client->request('GET', '/pratique/nouveautes/laravel-13');

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('.pr-written'));
        $this->assertStringContainsString(
            "2 exercices courts, autour de Files d'attente et Événements.",
            preg_replace('/\s+/u', ' ', $crawler->filter('.lead')->text()) ?? '',
            'La notion la plus travaillée ouvre la liste.',
        );
        $this->assertStringContainsString("Files d'attente", (string) $crawler->filter('meta[name="description"]')->attr('content'));
    }

    /** Symfony, lui, garde ses mineures à part : 8.1 et 8.2 ne se mélangent pas sous « Symfony 8 ». */
    public function testLesMineuresDeSymfonyRestentDistinctes(): void
    {
        foreach (['/pratique/nouveautes/symfony-8', '/pratique/nouveautes/symfony-8-1', '/pratique/nouveautes/symfony-8-2'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseStatusCodeSame(404, $url.' : une version qu\'un seul exercice pratique n\'a pas de page.');
        }
    }

    /** La pastille de version d'un exercice mène à sa page ; sans page, elle reste une pastille. */
    public function testLaPageDUnExerciceMeneACelleDeSaVersion(): void
    {
        $crawler = $this->client->request('GET', '/pratique/laravel-tot');
        $this->assertResponseIsSuccessful();
        $this->assertSame('/pratique/nouveautes/laravel-13', $crawler->filter('.practice-head a.tag.framework')->attr('href'));
        $this->assertSame('Laravel 13.24', $crawler->filter('.practice-head a.tag.framework')->text(), 'La pastille garde la version exacte de l\'exercice.');

        $crawler = $this->client->request('GET', '/pratique/symfony-huit-un');
        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('.practice-head a.tag.framework'));
        $this->assertSelectorTextContains('.practice-head .tag.framework', 'Symfony 8.1');
    }
}
