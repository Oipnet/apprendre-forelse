<?php

namespace App\Tests\Seo;

use App\Seo\IndexNow;
use App\Seo\IndexNowSubmission;
use App\Seo\Sitemap;
use App\Tests\DatabaseTrait;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** IndexNow : les pages du sitemap partent une fois par date, la clé est servie ; le service est simulé, jamais appelé. */
final class IndexNowTest extends WebTestCase
{
    use DatabaseTrait;

    private const string KEY = 'cle-de-test-0123456789';

    /** @var list<array{url: string, body: array<string, mixed>}> */
    private array $requests = [];
    /** Statut HTTP que répond le faux IndexNow. */
    private int $status = 200;
    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
    }

    private function indexNow(string $key = self::KEY, bool $indexing = true): IndexNow
    {
        $container = static::getContainer();
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['url' => $url, 'body' => json_decode($options['body'], true)];

            return new MockResponse(429 === $this->status ? 'Trop de requêtes' : '', ['http_code' => $this->status]);
        });

        return new IndexNow($container->get(Sitemap::class), $http, $this->cache, $container->get(UrlGeneratorInterface::class), new NullLogger(), $key, $indexing);
    }

    /** Le noyau, avec l'adresse publique d'un vrai site : IndexNow refuse les adresses en localhost. */
    private function boot(string $host = 'apprendre.example'): void
    {
        self::bootKernel();
        $this->resetDatabase();
        static::getContainer()->get(UrlGeneratorInterface::class)->getContext()->setHost($host);
    }

    public function testTheFirstRunSubmitsTheWholeSitemapThenOnlyChanges(): void
    {
        $this->boot();
        $sitemap = array_column(static::getContainer()->get(Sitemap::class)->entries(), 'loc');
        $indexNow = $this->indexNow();

        $this->assertSame(\count($sitemap), $indexNow->submit()->submitted);
        $this->assertCount(1, $this->requests);
        $this->assertSame(IndexNow::ENDPOINT, $this->requests[0]['url']);
        $this->assertSame(['host' => 'apprendre.example', 'key' => self::KEY, 'keyLocation' => 'http://apprendre.example/indexnow.txt', 'urlList' => $sitemap], $this->requests[0]['body']);

        // Rien n'a changé : personne n'est contacté.
        $this->assertSame([], $indexNow->pending());
        $this->assertEquals(new IndexNowSubmission(0), $indexNow->submit());
        $this->assertCount(1, $this->requests);
    }

    public function testARefusedSubmissionIsRetriedOnTheNextRun(): void
    {
        $this->boot();
        $indexNow = $this->indexNow();

        $this->status = 429;
        $submission = $indexNow->submit();
        $this->assertSame(0, $submission->submitted);
        $this->assertStringContainsString('HTTP 429', (string) $submission->error);
        $this->assertStringContainsString('Trop de requêtes', (string) $submission->error, 'Le corps de la réponse dit pourquoi.');
        $this->assertNotSame([], $indexNow->pending());

        $this->status = 202;
        $this->assertGreaterThan(0, $indexNow->submit()->submitted);
        $this->assertSame([], $indexNow->pending());
    }

    public function testLocalAddressesAreNotSent(): void
    {
        $this->boot('localhost');

        $submission = $this->indexNow()->submit();
        $this->assertSame(0, $submission->submitted);
        $this->assertStringContainsString('DEFAULT_URI', (string) $submission->error);
        $this->assertSame([], $this->requests);
    }

    public function testNothingIsSentWithoutAValidKeyOrWhenIndexingIsOff(): void
    {
        $this->boot();

        foreach ([$this->indexNow(''), $this->indexNow('trop'), $this->indexNow('cle/invalide!'), $this->indexNow(self::KEY, false)] as $indexNow) {
            $this->assertFalse($indexNow->isEnabled());
            $this->assertNull($indexNow->key());
            $this->assertEquals(new IndexNowSubmission(0), $indexNow->submit());
        }
        $this->assertSame([], $this->requests);
    }

    public function testNoKeyFileWithoutAKey(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/indexnow.txt');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testTheKeyFileIsServed(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        static::getContainer()->set(IndexNow::class, $this->indexNow());
        $client->request('GET', '/indexnow.txt');
        $this->assertResponseIsSuccessful();
        $this->assertSame(self::KEY, $client->getResponse()->getContent());
        $this->assertStringStartsWith('text/plain', (string) $client->getResponse()->headers->get('Content-Type'));
    }
}
