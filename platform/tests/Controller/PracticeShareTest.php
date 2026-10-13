<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le partage d'un exercice de la Pratique : son image d'aperçu, dessinée par le moteur, et ses liens de partage,
 * sur la page de l'exercice (playground) comme sur sa page publique d'une instance sur invitation.
 */
final class PracticeShareTest extends WebTestCase
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

    public function testLaPageAnnonceSonImage(): void
    {
        $crawler = $this->client->request('GET', '/pratique/nouveaute-recente');
        $this->assertResponseIsSuccessful();

        $image = (string) $crawler->filter('meta[property="og:image"]')->attr('content');
        $this->assertMatchesRegularExpression('#^http://localhost/pratique/nouveaute-recente/image-de-partage\?v=[0-9a-f]{16}$#', $image);
        $this->assertSame('1200', $crawler->filter('meta[property="og:image:width"]')->attr('content'));
        $this->assertSame('630', $crawler->filter('meta[property="og:image:height"]')->attr('content'));
        $this->assertSame('Pratique · Symfony 8.1 : Une nouveauté récente', $crawler->filter('meta[property="og:image:alt"]')->attr('content'));
        $this->assertSame('summary_large_image', $crawler->filter('meta[name="twitter:card"]')->attr('content'));

        $article = array_values(array_filter(
            array_map(static fn (string $json) => json_decode($json, true), $crawler->filter('script[type="application/ld+json"]')->extract(['_text'])),
            static fn (mixed $data) => \is_array($data) && 'Article' === ($data['@type'] ?? null),
        ));
        $this->assertSame($image, $article[0]['image'] ?? null, 'Les données structurées montrent la même image.');

        // L'image elle-même : un PNG au format des aperçus, figé sous cette adresse.
        $this->client->request('GET', $image);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'image/png');
        $this->assertStringContainsString('immutable', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $file = $this->client->getResponse()->getFile();
        $this->assertSame([1200, 630], \array_slice((array) getimagesize($file->getPathname()), 0, 2));

        // Sans la bonne version (un ancien aperçu), l'image courante, gardée moins longtemps.
        $this->client->request('GET', '/pratique/nouveaute-recente/image-de-partage?v=ancienne');
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('immutable', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testPasDImagePourUnExerciceInconnuOuPasEncorePublie(): void
    {
        $this->client->request('GET', '/pratique/inconnu/image-de-partage');
        $this->assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/pratique/programme/image-de-partage');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testLePlaygroundRecoitLesLiensDePartage(): void
    {
        $crawler = $this->client->request('GET', '/pratique/nouveaute-recente');
        $config = json_decode((string) $crawler->filter('[data-playground]')->attr('data-config'), true);

        $this->assertStringContainsString('Symfony 8.1 : « Une nouveauté récente »', $config['share']['invite']['text']);
        $this->assertStringStartsWith('J\'ai réussi l\'exercice « Une nouveauté récente » (Symfony 8.1)', $config['share']['success']['text']);
        $this->assertSame('http://localhost/pratique/nouveaute-recente?utm_source=lien&utm_medium=partage&utm_campaign=exercice', $config['share']['invite']['lien']);
        $this->assertStringStartsWith('https://www.linkedin.com/sharing/share-offsite/?url=http%3A%2F%2Flocalhost%2Fpratique%2Fnouveaute-recente%3Futm_source%3Dlinkedin', $config['share']['invite']['linkedin']);
    }

    public function testSurInvitationLaPagePubliqueProposeLesBoutons(): void
    {
        $initial = $_SERVER['REGISTRATION_INVITE_ONLY'] ?? '0';
        $_SERVER['REGISTRATION_INVITE_ONLY'] = $_ENV['REGISTRATION_INVITE_ONLY'] = '1';
        try {
            self::ensureKernelShutdown();
            $this->client = static::createClient();
            $crawler = $this->client->request('GET', '/pratique/point-precis');
        } finally {
            $_SERVER['REGISTRATION_INVITE_ONLY'] = $_ENV['REGISTRATION_INVITE_ONLY'] = $initial;
        }
        $this->assertResponseIsSuccessful();

        $share = $crawler->filter('.share[data-share]');
        $this->assertCount(1, $share);
        $this->assertSame(['LinkedIn', 'X', 'Bluesky'], $share->filter('a[data-share-network]')->extract(['_text']));
        $this->assertSame('_blank', $share->filter('a[data-share-network="x"]')->attr('target'));
        $data = json_decode((string) $share->attr('data-share'), true);
        $this->assertSame('http://localhost/pratique/point-precis?utm_source=lien&utm_medium=partage&utm_campaign=exercice', $data['lien']);
        $this->assertSame(['exercice' => 'point-precis', 'parcours' => 'pratique'], $data['mesure']);
    }
}
