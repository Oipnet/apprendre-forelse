<?php

namespace App\Tests\Seo;

use App\Seo\IndexNow;
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

            return new MockResponse('', ['http_code' => $this->status]);
        });

        return new IndexNow($container->get(Sitemap::class), $http, $this->cache, $container->get(UrlGeneratorInterface::class), new NullLogger(), $key, $indexing);
    }

    public function testTheFirstRunSubmitsTheWholeSitemapThenOnlyChanges(): void
    {
        self::bootKernel();
        $this->resetDatabase();
        $sitemap = array_column(static::getContainer()->get(Sitemap::class)->entries(), 'loc');
        $indexNow = $this->indexNow();

        $this->assertSame(\count($sitemap), $indexNow->submit());
        $this->assertCount(1, $this->requests);
        $this->assertSame(IndexNow::ENDPOINT, $this->requests[0]['url']);
        $this->assertSame(['host' => 'localhost', 'key' => self::KEY, 'keyLocation' => 'http://localhost/indexnow.txt', 'urlList' => $sitemap], $this->requests[0]['body']);

        // Rien n'a changé : personne n'est contacté.
        $this->assertSame([], $indexNow->pending());
        $this->assertSame(0, $indexNow->submit());
        $this->assertCount(1, $this->requests);
    }

    public function testARefusedSubmissionIsRetriedOnTheNextRun(): void
    {
        self::bootKernel();
        $this->resetDatabase();
        $indexNow = $this->indexNow();

        $this->status = 429;
        $this->assertSame(0, $indexNow->submit());
        $this->assertNotSame([], $indexNow->pending());

        $this->status = 202;
        $this->assertGreaterThan(0, $indexNow->submit());
        $this->assertSame([], $indexNow->pending());
    }

    public function testNothingIsSentWithoutAValidKeyOrWhenIndexingIsOff(): void
    {
        self::bootKernel();
        $this->resetDatabase();

        foreach ([$this->indexNow(''), $this->indexNow('trop'), $this->indexNow('cle/invalide!'), $this->indexNow(self::KEY, false)] as $indexNow) {
            $this->assertFalse($indexNow->isEnabled());
            $this->assertNull($indexNow->key());
            $this->assertSame(0, $indexNow->submit());
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
