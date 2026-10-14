<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'accueil en anglais (/en), avec le thème du moteur : ses textes, les liens entre les deux langues, et les
 * adresses du site qui ne prennent pas le /en de la page.
 */
final class HomeEnglishTest extends WebTestCase
{
    use DatabaseTrait;

    public function testLAccueilAnglais(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $crawler = $client->request('GET', '/en');

        $this->assertResponseIsSuccessful();
        $this->assertSame('en', $crawler->filter('html')->attr('lang'));
        $this->assertSame('Learn to code by coding in your browser | default', $crawler->filter('title')->text());
        $this->assertSame('Frequently asked questions', $crawler->filter('#questions h2, .home-faq h2')->first()->text());
        $this->assertSame('Learn to code by coding in the browser.', $crawler->filter('.footer-brand p')->first()->text());
        $this->assertSame('In French', $crawler->filter('.track-language')->first()->text(), 'Les parcours, eux, sont en français.');
        $this->assertSame('http://localhost/en', $crawler->filter('link[rel="canonical"]')->attr('href'));
        $this->assertSame('/en', $crawler->filter('.site-header a')->first()->attr('href'), 'Le logo ramène à l\'accueil anglais.');
    }

    public function testLesDeuxAccueilsSeNommentLUnLAutre(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        foreach (['/' => 'http://localhost/', '/en' => 'http://localhost/en'] as $path => $self) {
            $crawler = $client->request('GET', $path);
            $alternates = [];
            foreach ($crawler->filter('link[rel="alternate"][hreflang]') as $link) {
                $alternates[$link->getAttribute('hreflang')] = $link->getAttribute('href');
            }
            $this->assertSame(['en' => 'http://localhost/en', 'fr' => 'http://localhost/', 'x-default' => 'http://localhost/'], $alternates, $path);
            $this->assertNotSame($self, $crawler->filter('.footer-language a')->attr('href'), 'Le pied de page mène à l\'autre langue.');
        }
        $this->assertSame('English version', $client->request('GET', '/')->filter('.footer-language a')->text());
        $this->assertCount(0, $client->getCrawler()->filter('.track-language'), 'En français, rien à signaler.');
    }

    /** L'éditeur et les fichiers du site n'ont qu'une adresse : celles des données structurées ne prennent pas le /en. */
    public function testLesDonneesStructureesGardentLaRacineDuSite(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $crawler = $client->request('GET', '/en');
        $json = implode('', $crawler->filter('script[type="application/ld+json"]')->each(static fn ($script) => $script->text()));

        $this->assertStringContainsString('"@id":"http://localhost/#organisation"', str_replace('\/', '/', $json));
        $this->assertStringNotContainsString('localhost/en/#', str_replace('\/', '/', $json));
    }
}
