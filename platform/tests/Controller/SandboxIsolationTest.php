<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Le bac à sable (SANDBOX_ORIGIN=http://127.0.0.1:8001) et la plateforme sont cloisonnés. */
final class SandboxIsolationTest extends WebTestCase
{
    private const array SANDBOX = ['HTTP_HOST' => '127.0.0.1:8001'];

    public function testLeRelaisEstServiSurLeBacASable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sandbox', server: self::SANDBOX);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('iframe#preview');
        // Seule la plateforme peut l'encadrer.
        $this->assertResponseHeaderSame('Content-Security-Policy', 'frame-ancestors http://localhost');
    }

    public function testLeBacASableNeSertAucunePageDeLaPlateforme(): void
    {
        $client = static::createClient();
        foreach (['/', '/parcours/decouverte', '/api/exercises/decouverte/01-bonjour', '/connexion'] as $path) {
            $client->request('GET', $path, server: self::SANDBOX);
            $this->assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testLaPlateformeNeSertPasLeRelais(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sandbox');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testLesPagesDeLaPlateformeNePeuventEtreEncadreesAilleurs(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseHeaderSame('Content-Security-Policy', "frame-ancestors 'self'");
        $this->assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
    }

    public function testUneEcritureVenantDUneAutreOrigineEstRefusee(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/progress/import', server: ['HTTP_ORIGIN' => 'http://127.0.0.1:8001', 'CONTENT_TYPE' => 'application/json'], content: '[]');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testHttpsImposeSurUnVraiDomaineSeulement(): void
    {
        $client = static::createClient();

        // Adresses absolues : une adresse relative reprendrait le schéma de la requête précédente.
        $client->request('GET', 'https://apprendre.example.test/mentions-legales');
        $this->assertResponseHeaderSame('Strict-Transport-Security', 'max-age=31536000');

        $client->request('GET', 'http://apprendre.example.test/mentions-legales');
        $this->assertResponseNotHasHeader('Strict-Transport-Security', 'Rien en HTTP : le navigateur l\'ignorerait.');

        $client->request('GET', 'https://localhost/mentions-legales');
        $this->assertResponseNotHasHeader('Strict-Transport-Security', 'Jamais pour localhost.');
    }
}
