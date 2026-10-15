<?php

namespace App\Tests\Controller;

use App\Entity\ExerciseProgress;
use App\Repository\ExerciseProgressRepository;
use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Les parcours en anglais (/en/tracks) : un parcours traduit (`i18n/en/` du parcours et de chacun de ses exercices)
 * y a ses pages, avec ses textes, ses fichiers et ses tests traduits. Pack de démo, dont le parcours est traduit ;
 * un parcours non traduit renvoie à sa page française.
 */
final class TrackEnglishTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testLeCatalogueAnglaisMontreLeParcoursTraduit(): void
    {
        $crawler = $this->client->request('GET', '/en/tracks');

        $this->assertResponseIsSuccessful();
        $this->assertSame('en', $crawler->filter('html')->attr('lang'));
        $this->assertSelectorExists('a[href="/en/tracks/decouverte"]');
        $this->assertSelectorTextContains('main', 'Discovery');
        $this->assertSame(['en' => 'http://localhost/en/tracks', 'fr' => 'http://localhost/parcours', 'x-default' => 'http://localhost/parcours'], $this->alternates($crawler));
        $this->assertSame(['en' => 'http://localhost/en/tracks', 'fr' => 'http://localhost/parcours', 'x-default' => 'http://localhost/parcours'], $this->alternates($this->client->request('GET', '/parcours')), 'Le catalogue français nomme l\'anglais.');
    }

    public function testLaPageDuParcoursEstEnAnglais(): void
    {
        $crawler = $this->client->request('GET', '/en/tracks/decouverte');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Discovery');
        $this->assertSelectorTextContains('main', 'Hello Symfony');
        $this->assertStringContainsString('Symfony online course', (string) $crawler->filter('title')->text());
        $this->assertSame('http://localhost/en/tracks/decouverte', $crawler->filter('link[rel="canonical"]')->attr('href'));
        $this->assertSame(['en' => 'http://localhost/en/tracks/decouverte', 'fr' => 'http://localhost/parcours/decouverte', 'x-default' => 'http://localhost/parcours/decouverte'], $this->alternates($crawler));
        $this->assertSame(['en' => 'http://localhost/en/tracks/decouverte', 'fr' => 'http://localhost/parcours/decouverte', 'x-default' => 'http://localhost/parcours/decouverte'], $this->alternates($this->client->request('GET', '/parcours/decouverte')), 'La page française nomme l\'anglaise.');

        $crawler = $this->client->request('GET', '/en/tracks/decouverte/chapter/bonjour/summary');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Hello Symfony');
        $this->assertSame('http://localhost/en/tracks/decouverte/chapter/bonjour/summary', $crawler->filter('link[rel="canonical"]')->attr('href'));
    }

    public function testLExerciceTraduitSeJoueEnAnglais(): void
    {
        $this->client->loginUser($this->createUser());
        $crawler = $this->client->request('GET', '/en/tracks/decouverte/01-bonjour');

        $this->assertResponseIsSuccessful();
        $this->assertSame('en', $crawler->filter('html')->attr('lang'));
        $config = json_decode((string) $crawler->filter('[data-playground]')->attr('data-config'), true);
        $this->assertSame('/en/api/exercises/decouverte/01-bonjour', $config['exerciseUrl']);
        $this->assertSame('/en/api/progress/decouverte/01-bonjour', $config['progress']['url']);

        $exercise = $this->json('GET', $config['exerciseUrl']);
        $this->assertResponseIsSuccessful();
        $this->assertSame('testThePageResponds', $exercise['objectives'][0]['test']);
        $this->assertStringContainsString('/hello', $exercise['tests']['tests/BonjourTest.php']);

        $this->json('POST', '/en/api/progress/decouverte/01-bonjour/complete', ['hintsUsed' => 0]);
        $this->assertResponseIsSuccessful();
        $progress = static::getContainer()->get(ExerciseProgressRepository::class)->findOneBy(['exerciseId' => '01-bonjour']);
        $this->assertInstanceOf(ExerciseProgress::class, $progress);
        $this->assertSame('en', $progress->getSubmittedLocale(), 'Le vérificateur la rejoue avec les tests anglais.');
    }

    public function testUnParcoursNonTraduitRenvoieAuFrancais(): void
    {
        $this->usePacks(__DIR__.'/../../../examples/packs', __DIR__.'/../Fixtures/packs/enchainement');
        try {
            self::ensureKernelShutdown();
            $this->client = static::createClient();
            $this->client->loginUser($this->createUser());

            $crawler = $this->client->request('GET', '/en/tracks');
            $this->assertSelectorExists('a[href="/parcours/debut"]', 'Le catalogue anglais montre le parcours en français, avec son lien français.');
            $this->assertSelectorExists('a[href="/en/tracks/decouverte"]');

            $this->client->request('GET', '/en/tracks/debut?utm_source=x');
            $this->assertResponseRedirects('/parcours/debut?utm_source=x', 302);
            $this->client->request('GET', '/en/tracks/debut/e1');
            $this->assertResponseRedirects('/parcours/debut/e1', 302);
            $this->client->request('GET', '/en/tracks/debut/chapter/c1/summary');
            $this->assertResponseRedirects('/parcours/debut/chapitre/c1/sommaire', 302);

            $this->client->request('GET', '/en/api/exercises/debut/e1');
            $this->assertResponseStatusCodeSame(404, 'L\'API ne redirige pas.');
            $this->client->request('GET', '/en/tracks/inconnu');
            $this->assertResponseStatusCodeSame(404, 'Un parcours qui n\'existe pas reste introuvable.');
            $this->client->request('GET', '/en/tracks/decouverte/chapter/inconnu/summary');
            $this->assertResponseStatusCodeSame(404, 'Le parcours est traduit : un chapitre inconnu reste une 404.');
        } finally {
            $this->restorePacks();
        }
    }

    /** @return array<string, mixed> */
    private function json(string $method, string $url, ?array $body = null): array
    {
        $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: null === $body ? null : json_encode($body));

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array<string, string> hreflang => adresse */
    private function alternates(Crawler $crawler): array
    {
        $alternates = [];
        foreach ($crawler->filter('link[rel="alternate"][hreflang]') as $link) {
            $alternates[$link->getAttribute('hreflang')] = $link->getAttribute('href');
        }
        ksort($alternates);

        return $alternates;
    }
}
