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
        $this->assertPageTitleSame('Page introuvable · default');
        $this->assertSelectorTextContains('.error-code', '404');
        $this->assertSelectorNotExists('.error-request', 'La méthode et le chemin ne s\'affichent qu\'en debug.');
        $this->assertSelectorExists('.error-actions a[href="/"]');
        $this->assertSelectorExists('.error-actions a[href="/compte"]', 'On reprend là où l\'on en était.');
        $this->assertSelectorExists('.site-footer', 'Le pied de page du site reste là.');
        $this->assertSelectorExists('meta[name="robots"][content="noindex"]');
        $this->assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        $this->assertSelectorNotExists('link[rel="canonical"]');
    }

    /** Une page fixe (#[Seo]) qui répond 404 : la page d'erreur ne se présente pas comme la page demandée. */
    public function testUnePageFixeIntrouvableNeGardePasSesBalises(): void
    {
        $client = static::createClient(['debug' => false]);
        $client->request('GET', '/auto-hebergement');

        $this->assertResponseStatusCodeSame(404);
        $this->assertPageTitleSame('Page introuvable · default');
        $this->assertSelectorNotExists('link[rel="canonical"]');
        $this->assertSelectorNotExists('meta[name="description"]');
    }

    public function testUnAccesInterditLeDitSansAfficherLeCompte(): void
    {
        $client = static::createClient(['debug' => false]);
        $this->resetDatabase();
        $client->loginUser($this->createUser());
        $client->request('GET', '/admin');

        $this->assertResponseStatusCodeSame(403);
        $this->assertSelectorTextContains('h1', 'Accès refusé');
        $this->assertSelectorNotExists('.account-name', 'En-tête réduit : pas de compte ni d\'XP sur une page d\'erreur.');
    }
}
