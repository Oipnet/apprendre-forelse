<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/** La page des offres (/formations) : celle que déclare le thème (« offers »), et rien sans elle. */
final class OffersTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    private const string THEME = <<<'YAML'
        name: Atelier
        person:
          name: Ada Lovelace
          jobTitle: Formatrice
          bio: 'Je forme des devs PHP.'
          booking: 'https://calendly.com/ada'
        offers:
          seo: {title: 'Formation Symfony en entreprise', description: 'Former une équipe, auditer une application.'}
          hero: {eyebrow: Pour les équipes, title: 'Formations et audits', lead: 'Je forme et j''audite.', button: 'Parlons-en', note: 'Appel de 30 minutes.'}
          showcase: {file: Voter.php, code: 'return true;', checks: ['Un client ne voit que ses factures'], result: 'Tests : 1 / 1'}
          stats: [{value: 20 ans, label: de code}]
          proof: 'Citée par A Week of Symfony.'
          catalog: {title: Trois offres}
          items:
            - {id: formation, tag: Formation, title: 'Former mon équipe', audience: 'Pour une équipe.', features: [Deux jours], price: '1 200 €', unit: 'HT / jour', amount: 1200, cta: Former}
            - {id: audit-acces, title: 'Auditer le contrôle d''accès', price: '2 400 €', amount: 2400, cta: Auditer}
          example: {title: Un constat, finding: 'Un client lit la facture d''un autre', link: {text: L'article, url: /blog/les-tests-en-direct}, rows: [{label: Route, value: '`GET /factures/{id}`'}, {label: Cause, value: 'Aucune vérification.'}]}
          faq: {title: Questions, items: [{question: 'Financement OPCO ?', answer: 'Non, pas de Qualiopi.'}]}
          cta: {title: On en parle ?, button: Réserver}
        YAML;

    private KernelBrowser $client;

    /** @var array{string|null, string|null} */
    private array $environment;

    private string $themes;

    protected function setUp(): void
    {
        $this->usePacks(__DIR__.'/../../../examples/packs', __DIR__.'/../Fixtures/packs/pratique');
        $this->client = static::createClient();
        $this->resetDatabase();
        $this->environment = [$_SERVER['THEMES_DIR'] ?? null, $_SERVER['THEME'] ?? null];
        $this->themes = sys_get_temp_dir().'/themes-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (['THEMES_DIR' => $this->environment[0], 'THEME' => $this->environment[1]] as $name => $value) {
            if (null === $value) {
                unset($_SERVER[$name], $_ENV[$name]);
            } else {
                $_SERVER[$name] = $_ENV[$name] = $value;
            }
        }
        (new Filesystem())->remove($this->themes);
        $this->restorePacks();
        parent::tearDown();
    }

    public function testLeThemeDuMoteurNAPasDePageDesOffres(): void
    {
        $this->client->request('GET', '/formations');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testLaPageMontreLesOffresEtLeursPrix(): void
    {
        $this->useTheme(self::THEME);
        $crawler = $this->client->request('GET', '/formations');

        $this->assertResponseIsSuccessful();
        $this->assertSame('Formations et audits', $crawler->filter('h1')->text());
        $this->assertSame(['formation', 'audit-acces'], $crawler->filter('.offers-card')->each(static fn ($card) => $card->attr('id')), 'Une ancre par offre.');
        $this->assertStringContainsString('1 200 €', $crawler->filter('#formation .offers-price')->text());
        $this->assertSame('https://calendly.com/ada', $crawler->filter('.offers-hero .offers-booking')->attr('href'), 'Le rendez-vous de la personne.');
        $this->assertSame(['en-tete', 'formation', 'audit-acces', 'dernier-appel'], $crawler->filter('.offers-booking')->each(static fn ($a) => $a->attr('data-umami-event-lieu')));
        $this->assertSame('/auteurs/ada-lovelace', $crawler->filter('.offers-page a[href^="/auteurs/"]')->attr('href'), 'La page auteur, sans la répéter.');
        $this->assertSame('GET /factures/{id}', $crawler->filter('.offers-finding dd code')->text(), 'Une valeur entre accents graves est du code.');
        $this->assertSame('Financement OPCO ?', $crawler->filter('.offers-faq summary')->text());
        $this->assertStringContainsString('Formation Symfony en entreprise | Atelier', $crawler->filter('title')->text());
        $this->assertFalse($this->client->getResponse()->headers->has('X-Robots-Tag'), 'La page des offres s\'indexe : elle est dans le sitemap.');

        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('"@type":"Service"', $html);
        $this->assertStringContainsString('"price":"1200","priceCurrency":"EUR"', $html);
        $this->assertStringContainsString('"@type":"FAQPage"', $html);
        $this->assertStringContainsString('#auteur"', $html, 'Rendu par la personne du thème, sous son @id.');

        $crawler = $this->client->request('GET', '/auteurs/ada-lovelace');
        $this->assertSame('/formations', $crawler->filter('.author-offers a')->attr('href'), 'La page auteur mène aux offres.');
        $this->assertFalse($this->client->getResponse()->headers->has('X-Robots-Tag'), 'La page auteur s\'indexe : elle est dans le sitemap.');

        $this->client->request('GET', '/en/training');
        $this->assertResponseStatusCodeSame(404, 'Pas de page dans une langue que le thème ne traduit pas.');

        $this->client->request('GET', '/sitemap.xml');
        $this->assertStringContainsString('/formations</loc>', (string) $this->client->getResponse()->getContent());
    }

    public function testSansRendezVousLaPageRenvoieAuContact(): void
    {
        $this->useTheme("name: Atelier\noffers:\n  hero: {title: Formations}\n  items: [{id: a, title: T, price: '1 €', cta: C}]\n");
        $crawler = $this->client->request('GET', '/formations');

        $this->assertResponseIsSuccessful();
        $this->assertSame('/contact', $crawler->filter('.offers-booking')->first()->attr('href'));
        $this->assertNull($crawler->filter('.offers-booking')->first()->attr('target'), 'Le contact s\'ouvre sur le site.');
        $this->assertCount(0, $crawler->filter('.offers-final'), 'Ce que le thème ne remplit pas n\'apparaît pas.');
    }

    private function useTheme(string $yaml): void
    {
        mkdir($this->themes.'/atelier', 0o777, true);
        file_put_contents($this->themes.'/atelier/theme.yaml', $yaml);
        $_SERVER['THEMES_DIR'] = $_ENV['THEMES_DIR'] = $this->themes;
        $_SERVER['THEME'] = $_ENV['THEME'] = 'atelier';
        static::ensureKernelShutdown();
        $this->client = static::createClient();
    }
}
