<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Hors debug (comme en prod), les erreurs HTML passent par templates/bundles/TwigBundle/Exception. */
final class ErrorPagesTest extends WebTestCase
{
    use DatabaseTrait;

    public function testUneAdresseInconnueAfficheLaPageIntrouvable(): void
    {
        $client = static::createClient(['debug' => false]);
        $client->request('GET', '/nulle-part/ici');

        $this->assertResponseStatusCodeSame(404);
        $this->assertPageTitleSame('Page introuvable · Forelse');
        $this->assertSelectorTextContains('.error-code', '404');
        $this->assertSelectorTextContains('.error-request', 'GET /nulle-part/ici');
        $this->assertSelectorExists('.error-actions a[href="/"]');
        $this->assertSelectorExists('.site-footer', 'Le pied de page du site reste là.');
        $this->assertSelectorExists('meta[name="robots"][content="noindex"]');
        $this->assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        $this->assertSelectorNotExists('link[rel="canonical"]');
    }

    public function testUnAccesInterditLeDitSansAfficherLeCompte(): void
    {
        $client = static::createClient(['debug' => false]);
        $this->resetDatabase();
        $client->loginUser($this->createUser());
        $client->request('GET', '/admin');

        $this->assertResponseStatusCodeSame(403);
        $this->assertSelectorTextContains('h1', 'Accès refusé');
        $this->assertSelectorNotExists('.lp-user', 'En-tête réduit : pas de compte ni d\'XP sur une page d\'erreur.');
    }
}
