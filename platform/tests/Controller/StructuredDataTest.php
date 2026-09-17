<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use App\Tests\PaymentTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Le JSON-LD de chaque page publique se décode, et décrit ce que la page montre. */
final class StructuredDataTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;
    use PaymentTrait;

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }

    /** @return array<string, array<string, mixed>> les objets schema.org de la page, par type (@graph déplié) */
    private function structuredData(KernelBrowser $client, string $path): array
    {
        $crawler = $client->request('GET', $path);
        $this->assertResponseIsSuccessful($path);

        $objects = [];
        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            $data = json_decode($script->textContent, true, flags: \JSON_THROW_ON_ERROR);
            $this->assertSame('https://schema.org', $data['@context'], $path);
            foreach ($data['@graph'] ?? [$data] as $object) {
                $this->assertArrayNotHasKey($object['@type'], $objects, sprintf('%s : un seul %s.', $path, $object['@type']));
                $objects[$object['@type']] = $object;
            }
        }

        return $objects;
    }

    /** @param array<string, mixed> $breadcrumb */
    private function assertBreadcrumb(array $expected, array $breadcrumb): void
    {
        $this->assertSame($expected, array_map(static fn (array $item) => [$item['position'], $item['name'], $item['item']], $breadcrumb['itemListElement']));
    }

    public function testAccueil(): void
    {
        $client = static::createClient();
        $data = $this->structuredData($client, '/');

        $this->assertSame(['Organization', 'WebSite', 'Person', 'FAQPage'], array_keys($data));
        $this->assertSame('http://localhost/', $data['WebSite']['url']);
        $this->assertSame('Arnaud Pointet', $data['Person']['name']);
        // La FAQ reprend exactement les questions affichées.
        $this->assertSame(
            $client->getCrawler()->filter('.lp-faq summary')->each(static fn ($summary) => $summary->text()),
            array_column($data['FAQPage']['mainEntity'], 'name'),
        );
        $this->assertStringContainsString('Découverte', $data['FAQPage']['mainEntity'][1]['acceptedAnswer']['text']);
    }

    public function testParcoursGratuit(): void
    {
        $client = static::createClient();
        $data = $this->structuredData($client, '/parcours/decouverte');

        $this->assertSame(['Course', 'BreadcrumbList'], array_keys($data));
        $course = $data['Course'];
        $this->assertSame('Découverte', $course['name']);
        $this->assertSame('fr', $course['inLanguage']);
        $this->assertSame('Online', $course['hasCourseInstance']['courseMode']);
        $this->assertSame(['@type' => 'Offer', 'category' => 'Free', 'price' => '0.00', 'priceCurrency' => 'EUR', 'url' => 'http://localhost/parcours/decouverte', 'availability' => 'https://schema.org/InStock'], $course['offers']);
        $this->assertArrayNotHasKey('numberOfCredits', $course);
        $this->assertBreadcrumb([[1, 'Accueil', 'http://localhost/'], [2, 'Découverte', 'http://localhost/parcours/decouverte']], $data['BreadcrumbList']);
    }

    public function testParcoursPayantAuPrixCourant(): void
    {
        $this->usePaidPack();
        $client = static::createClient();
        $this->resetDatabase();
        $this->setPrice('payant', 7900, 4900);

        $offer = $this->structuredData($client, '/parcours/payant')['Course']['offers'];

        $this->assertSame('Paid', $offer['category']);
        $this->assertSame('49.00', $offer['price'], 'Le prix fondateur, s\'il s\'applique.');
        $this->assertSame('EUR', $offer['priceCurrency']);
    }

    public function testUnParcoursAvecDesDureesLesAnnonce(): void
    {
        $this->usePaidPack();
        $client = static::createClient();
        $this->resetDatabase();

        $course = $this->structuredData($client, '/parcours/payant')['Course'];
        $this->assertSame('PT1H45M', $course['timeRequired'], '20 + 35 + 50 minutes.');
        $this->assertSelectorTextContains('.track-duration', 'Environ 1 h 45 de pratique');
        $this->assertSelectorTextContains('#chapitre-2 .tr-chapter-meta', '≈ 1 h 30', 'Chapitre : 85 minutes, au quart d\'heure près.');
        $this->assertSelectorTextContains('.exercises li:first-child .meta', '20 min');

        $client->request('GET', '/');
        $this->assertSelectorTextContains('.lp-track .count', 'environ 1 h 45');

        $this->restorePacks();
        $client->request('GET', '/parcours/decouverte');
        $this->assertSelectorTextNotContains('.track-duration', 'de pratique', 'Sans durée sur ses exercices, rien n\'est promis.');
    }

    public function testExercice(): void
    {
        $client = static::createClient();
        $data = $this->structuredData($client, '/parcours/decouverte/01-bonjour');

        $this->assertSame(['BreadcrumbList'], array_keys($data));
        $this->assertCount(3, $data['BreadcrumbList']['itemListElement']);
        $this->assertSame('http://localhost/parcours/decouverte/01-bonjour', $data['BreadcrumbList']['itemListElement'][2]['item']);
    }

    public function testPratique(): void
    {
        $client = static::createClient();
        $list = $this->structuredData($client, '/pratique');
        $this->assertBreadcrumb([[1, 'Accueil', 'http://localhost/'], [2, 'Pratique', 'http://localhost/pratique']], $list['BreadcrumbList']);

        $data = $this->structuredData($client, '/pratique/exemple-map-request-header');
        $this->assertSame(['Article', 'BreadcrumbList'], array_keys($data));
        $article = $data['Article'];
        $this->assertSame('2026-09-16', $article['datePublished']);
        $this->assertGreaterThanOrEqual($article['datePublished'], $article['dateModified']);
        $this->assertSame('Arnaud Pointet', $article['author']['name']);
        $this->assertCount(3, $data['BreadcrumbList']['itemListElement']);
    }

    public function testUnTexteDePackNePeutPasFermerLaBalise(): void
    {
        $json = \App\Twig\JsonLdExtension::encode(['name' => '</script>']);

        $this->assertStringNotContainsString('<', $json);
        $this->assertSame(['name' => '</script>'], json_decode($json, true));
    }
}
