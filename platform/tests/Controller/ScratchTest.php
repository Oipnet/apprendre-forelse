<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le bac à sable : un projet Symfony ou Laravel vierge, servi au playground comme un exercice sans objectif, ouvert à
 * tout visiteur. Le code partagé voyage dans le fragment de l'adresse : rien ici ne le reçoit.
 */
final class ScratchTest extends WebTestCase
{
    public function testLaPageDAccueilProposeSymfonyEtLaravel(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/bac-a-sable');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['/bac-a-sable/symfony#nouveau', '/bac-a-sable/laravel#nouveau'], $crawler->filter('.scratch-frameworks a')->extract(['href']));
        $this->assertSelectorExists('header nav a[href="/bac-a-sable"]', 'Le bac à sable est dans le menu.');
        $this->assertSelectorExists('.site-footer a[href="/bac-a-sable"]');
    }

    public function testLaPageDuProjetMonteLePlaygroundSansCompte(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/bac-a-sable/laravel');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Bac à sable Laravel');
        $config = json_decode((string) $crawler->filter('[data-playground]')->attr('data-config'), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('scratch', $config['context']);
        $this->assertSame('/api/bac-a-sable/laravel', $config['exerciseUrl']);
        $this->assertSame(['mode' => 'local'], $config['progress'], 'Le brouillon reste dans le navigateur, compte ou pas.');
        $this->assertNull($config['mentor']);
        $this->assertNull($config['feedbackUrl']);
        $this->assertSame('/bac-a-sable', $config['back']['url']);
        $this->assertStringStartsWith('http://localhost/bac-a-sable/laravel?', $config['share']['invite']['lien']);
        $this->assertStringContainsString('utm_campaign=bac-a-sable', $config['share']['invite']['lien']);
        $this->assertSame(['/bac-a-sable/symfony', '/pratique'], array_column($config['scratch']['links'], 'url'), 'Ensuite : l\'autre framework, puis la Pratique.');
    }

    public function testLeProjetEstUnExerciceSansObjectifNiTest(): void
    {
        $client = static::createClient();
        $payload = $this->json($client, '/api/bac-a-sable/symfony');

        $this->assertResponseIsSuccessful();
        $this->assertSame('symfony-8', $payload['environment']['id']);
        $this->assertSame('src/Controller/AccueilController.php', $payload['open']);
        $this->assertSame(['src/Controller/AccueilController.php', 'templates/accueil.html.twig'], array_keys($payload['files']));
        $this->assertStringContainsString("#[Route('/', name: 'accueil')]", $payload['files']['src/Controller/AccueilController.php']);
        $this->assertSame([], $payload['objectives']);
        $this->assertSame([], $payload['tests']);
        $this->assertSame([], $payload['hints']);
        $this->assertNotContains('config/packages/*.yaml', $payload['editable'], 'Un lien partagé ne touche pas à la configuration du framework.');
        $this->assertStringNotContainsString('<h1', $payload['instructionsHtml']);
    }

    public function testEnAnglaisLeProjetEtLaConsigneSontTraduits(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/playground/laravel');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Laravel playground');
        $config = json_decode((string) $crawler->filter('[data-playground]')->attr('data-config'), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('/en/api/bac-a-sable/laravel', $config['exerciseUrl']);

        $payload = $this->json($client, '/en/api/bac-a-sable/laravel');
        $this->assertStringContainsString('Hello Laravel!', $payload['files']['routes/web.php']);
        $this->assertStringContainsString('Laravel 13 project runs in your browser', $payload['instructions']);
    }

    public function testUnFrameworkInconnuNExistePas(): void
    {
        $client = static::createClient();
        foreach (['/bac-a-sable/nuxt', '/api/bac-a-sable/nuxt'] as $url) {
            $client->request('GET', $url);
            $this->assertResponseStatusCodeSame(404, $url);
        }
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client, string $url): array
    {
        $client->request('GET', $url, server: ['HTTP_ACCEPT' => 'application/json']);

        return json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
