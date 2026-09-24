<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Politique de sécurité du contenu (App\Security\ContentSecurityPolicy) et déconnexion protégée. */
final class ContentSecurityPolicyTest extends WebTestCase
{
    use DatabaseTrait;

    private const array SANDBOX = ['HTTP_HOST' => '127.0.0.1:8001'];

    public function testLesPagesNAcceptentQueLesScriptsDuSite(): void
    {
        $client = static::createClient();
        $client->request('GET', '/pratique');

        $this->assertSame(
            "script-src 'self' 'wasm-unsafe-eval'; object-src 'none'; base-uri 'self'; report-uri /csp-rapport",
            $this->policy($client),
        );
        // frame-ancestors reste bloquante : un en-tête Report-Only l'ignorerait.
        $this->assertResponseHeaderSame('Content-Security-Policy', "frame-ancestors 'self'");
    }

    public function testLePlaygroundAutoriseEvalPourLeSimulateurNuxt(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        // Connecté : un visiteur voit la page publique de l'exercice, sans le playground.
        $client->loginUser($this->createUser());
        $client->request('GET', '/parcours/decouverte/01-bonjour');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#app[data-config], [data-config]');
        $this->assertStringContainsString("script-src 'self' 'wasm-unsafe-eval' 'unsafe-eval';", $this->policy($client));
    }

    public function testLeRelaisDuBacASableAussi(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sandbox', server: self::SANDBOX);

        $this->assertStringStartsWith("script-src 'self' 'wasm-unsafe-eval';", $this->policy($client));
    }

    public function testLesRapportsSontRecusSurLesDeuxOrigines(): void
    {
        $client = static::createClient();
        $report = json_encode(['csp-report' => [
            'document-uri' => 'http://localhost/pratique',
            'violated-directive' => 'script-src',
            'blocked-uri' => 'inline',
        ]]);

        $client->request('POST', '/csp-rapport', server: ['CONTENT_TYPE' => 'application/csp-report'], content: $report);
        $this->assertResponseStatusCodeSame(204);

        $client->request('POST', '/csp-rapport', server: self::SANDBOX + ['CONTENT_TYPE' => 'application/csp-report'], content: $report);
        $this->assertResponseStatusCodeSame(204);
    }

    public function testUnAutreSiteNePeutPlusDeconnecter(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $client->loginUser($this->createUser());

        // Lien ou image posés sur un autre site : sans jeton, ou annoncés d'ailleurs par le navigateur.
        $client->request('GET', '/deconnexion');
        $client->request('GET', '/deconnexion?_csrf_token=csrf-token', server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        $client->request('GET', '/compte');
        $this->assertResponseIsSuccessful('Toujours connectée.');

        // Le lien du menu, cliqué sur le site.
        $client->request('GET', '/deconnexion?_csrf_token=csrf-token', server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $this->assertResponseRedirects('/');
        $client->request('GET', '/compte');
        $this->assertResponseRedirects('/connexion');
    }

    /** La politique des scripts, en signalement seul par défaut (CSP_REPORT_ONLY=1). */
    private function policy(KernelBrowser $client): string
    {
        return (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only');
    }
}
