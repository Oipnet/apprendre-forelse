<?php

namespace App\Tests\Controller;

use App\Entity\ExerciseProgress;
use App\Repository\ExerciseProgressRepository;
use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La Pratique en anglais (/en/practice) : seuls les exercices traduits (`i18n/en/` de l'exercice) y existent, avec
 * leurs textes, leurs fichiers et leurs tests traduits. Pack de démo (traduit) et pack de test « pratique » (pas
 * traduit).
 */
final class PracticeEnglishTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->usePacks(__DIR__.'/../../../examples/packs', __DIR__.'/../Fixtures/packs/pratique');
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }

    public function testLaListeAnglaiseNeMontreQueLesExercicesTraduits(): void
    {
        $crawler = $this->client->request('GET', '/en/practice');

        $this->assertResponseIsSuccessful();
        $this->assertSame('en', $crawler->filter('html')->attr('lang'));
        $this->assertSame(['/en/practice/exemple-map-request-header'], $crawler->filter('a[href^="/en/practice/"]')->each(static fn ($a) => $a->attr('href')));
        $this->assertSelectorTextContains('main', 'Read a header with #[MapRequestHeader]');
        $this->assertSelectorTextContains('h1', 'Use a feature, right now');
        $this->assertCount(0, $crawler->filter('a[href^="/pratique/nouveautes/"]'), 'Les pages de nouveautés sont en français.');
        $this->assertSame(['en' => 'http://localhost/en/practice', 'fr' => 'http://localhost/pratique', 'x-default' => 'http://localhost/pratique'], $this->alternates($crawler));

        $this->assertSame(['en' => 'http://localhost/en/practice', 'fr' => 'http://localhost/pratique', 'x-default' => 'http://localhost/pratique'], $this->alternates($this->client->request('GET', '/pratique')), 'La liste française nomme la liste anglaise.');
    }

    public function testUnExerciceNonTraduitNExistePasEnAnglais(): void
    {
        $this->client->request('GET', '/en/practice/point-precis');
        $this->assertResponseStatusCodeSame(404);

        $this->json($this->client, 'GET', '/en/api/exercises/pratique/point-precis');
        $this->assertResponseStatusCodeSame(404);

        $this->assertSame([], $this->alternates($this->client->request('GET', '/pratique/point-precis')), 'Pas de version anglaise à annoncer.');
    }

    public function testLExerciceTraduitSeJoueEnAnglais(): void
    {
        $crawler = $this->client->request('GET', '/en/practice/exemple-map-request-header');

        $this->assertResponseIsSuccessful();
        $this->assertSame('Read a header with #[MapRequestHeader]', $crawler->filter('h1')->text());
        $this->assertStringContainsString('Rewrite VersionController with', $crawler->filter('.exercise-instructions')->text());
        $this->assertSame(['en' => 'http://localhost/en/practice/exemple-map-request-header', 'fr' => 'http://localhost/pratique/exemple-map-request-header', 'x-default' => 'http://localhost/pratique/exemple-map-request-header'], $this->alternates($crawler));
        $this->assertSame(['/en/concepts/controller'], $crawler->filter('.exercise-concepts a')->each(static fn ($a) => $a->attr('href')), 'Les pastilles mènent aux notions anglaises.');

        $config = json_decode((string) $crawler->filter('[data-playground]')->attr('data-config'), true);
        $this->assertSame('/en/api/exercises/pratique/exemple-map-request-header', $config['exerciseUrl']);
        $this->assertSame(['title' => 'Practice', 'url' => '/en/practice'], $config['back']);

        $exercise = $this->json($this->client, 'GET', $config['exerciseUrl']);
        $this->assertResponseIsSuccessful();
        $this->assertSame('Read a header with #[MapRequestHeader]', $exercise['title']);
        $this->assertSame('testTheRequestedVersionIsRead', $exercise['objectives'][0]['test']);
        $this->assertStringContainsString('Missing X-Api-Version header.', $exercise['files']['src/Controller/VersionController.php']);
        $this->assertStringContainsString('Requested version: 2', $exercise['tests']['tests/Pratique/VersionTest.php']);
        $this->assertStringContainsString('Symfony runs entirely in your browser', $exercise['environment']['framework']['startupNote'] ?? json_encode($exercise['environment']['framework']));
        $this->assertNull($exercise['practice']['versionUrl'], 'Les pages de nouveautés sont en français.');
        $this->assertNull($exercise['practice']['track'], 'Les parcours sont en français.');
        $this->assertSame(['Controller' => '/en/concepts/controller'], $exercise['conceptUrls']);
    }

    /** La réussite garde la langue de l'exercice : c'est avec ses tests traduits que le verificateur la rejoue. */
    public function testUneReussiteEnAnglaisSeVerifieAvecLesTestsAnglais(): void
    {
        $this->client->loginUser($user = $this->createUser());

        $config = json_decode((string) $this->client->request('GET', '/en/practice/exemple-map-request-header')->filter('[data-playground]')->attr('data-config'), true);
        $this->assertSame(['mode' => 'api', 'url' => '/en/api/progress/pratique/exemple-map-request-header'], $config['progress']);
        $this->json($this->client, 'POST', '/en/api/progress/pratique/exemple-map-request-header/complete', ['hintsUsed' => 0]);
        $this->assertResponseIsSuccessful();

        $progress = static::getContainer()->get(ExerciseProgressRepository::class)->findOneBy(['exerciseId' => 'exemple-map-request-header']);
        $this->assertInstanceOf(ExerciseProgress::class, $progress);
        $this->assertSame('en', $progress->getSubmittedLocale());

        $this->json($this->client, 'POST', '/api/progress/pratique/exemple-map-request-header/complete', ['hintsUsed' => 0]);
        $this->assertSame('en', $progress->getSubmittedLocale(), 'Déjà réussi : la réussite ne change plus.');
    }

    /** @return array<string, string> hreflang => adresse */
    private function alternates(\Symfony\Component\DomCrawler\Crawler $crawler): array
    {
        $alternates = [];
        foreach ($crawler->filter('link[rel="alternate"][hreflang]') as $link) {
            $alternates[$link->getAttribute('hreflang')] = $link->getAttribute('href');
        }
        ksort($alternates);

        return $alternates;
    }
}
