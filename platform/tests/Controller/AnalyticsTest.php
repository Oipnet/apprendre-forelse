<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Mesure d'audience : la balise n'apparaît que sur une instance qui l'a configurée, et la page vie privée suit. */
final class AnalyticsTest extends WebTestCase
{
    use DatabaseTrait;

    public function testAucuneBaliseSansConfiguration(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('data-website-id', (string) $client->getResponse()->getContent());
    }

    public function testLaBaliseEstPoseeSurLesPagesPubliques(): void
    {
        $client = self::clientAvecMesure();
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $config = $crawler->filter('meta[name="mesure-audience"]');
        $this->assertCount(1, $config);
        $this->assertSame('/mesure/traceur.js', $config->attr('data-src'));
        $this->assertSame('11111111-2222-3333-4444-555555555555', $config->attr('data-website-id'));
        // Le fragment d'une adresse du bac à sable porte le code du visiteur : le traceur ne l'envoie pas.
        $this->assertSame('true', $config->attr('data-exclude-hash'));
        // Le traceur est posé par l'entrée « mesure » (playground/src/mesure.ts), qui écarte les robots : un module,
        // donc différé, qui n'empêche pas l'affichage de la page.
        $this->assertCount(1, $crawler->filter('script[type="module"][src*="/build/"][src*="mesure"]'));
    }

    /** L'aperçu du code des apprenants sort d'une autre origine et d'un autre gabarit : il n'est pas mesuré. */
    public function testLeBacASableNEstPasMesure(): void
    {
        $client = self::clientAvecMesure();
        $client->request('GET', '/sandbox', server: ['HTTP_HOST' => '127.0.0.1:8001']);

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('data-website-id', (string) $client->getResponse()->getContent());
    }

    /** Annoncer « aucun outil de mesure d'audience » alors qu'on en pose un serait faux. */
    public function testLaPageVieRiveeDitCeQuiEstMesure(): void
    {
        $client = static::createClient();
        $client->request('GET', '/confidentialite');
        $this->assertResponseIsSuccessful();
        $sans = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString("aucun outil de mesure d'audience", $sans);

        self::ensureKernelShutdown();
        $client = self::clientAvecMesure();
        $client->request('GET', '/confidentialite');
        $this->assertResponseIsSuccessful();
        $avec = (string) $client->getResponse()->getContent();
        $this->assertStringNotContainsString("aucun outil de mesure d'audience", $avec);
        $this->assertStringContainsString("Mesure d'audience", $avec);
        // Sans cookie ni consentement : la page ne doit pas se mettre à promettre un bandeau.
        $this->assertStringContainsString("pas de bandeau à accepter", $avec);
    }

    private static function clientAvecMesure(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $_SERVER['ANALYTICS_SCRIPT_URL'] = $_ENV['ANALYTICS_SCRIPT_URL'] = '/mesure/traceur.js';
        $_SERVER['ANALYTICS_WEBSITE_ID'] = $_ENV['ANALYTICS_WEBSITE_ID'] = '11111111-2222-3333-4444-555555555555';

        return static::createClient();
    }

    protected function tearDown(): void
    {
        $_SERVER['ANALYTICS_SCRIPT_URL'] = $_ENV['ANALYTICS_SCRIPT_URL'] = '';
        $_SERVER['ANALYTICS_WEBSITE_ID'] = $_ENV['ANALYTICS_WEBSITE_ID'] = '';
        parent::tearDown();
    }
}
