<?php

namespace App\Tests\Controller;

use App\Instance\SelfHostingPage;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SelfHostingTest extends WebTestCase
{
    public function testLaPageEstEteinteParDefaut(): void
    {
        $client = static::createClient();

        $client->request('GET', '/auto-hebergement');
        $this->assertResponseStatusCodeSame(404);

        $client->request('GET', '/');
        $this->assertSelectorNotExists('.site-footer a[href="/auto-hebergement"]');
        $this->assertSelectorExists('a[href="'.SelfHostingPage::REPOSITORY.'"]');
        $client->request('GET', '/sitemap.xml');
        $this->assertStringNotContainsString('/auto-hebergement', (string) $client->getResponse()->getContent());
    }

    public function testAllumeeElleEstLieeEtReferencee(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set(SelfHostingPage::class, new SelfHostingPage(true));

        $client->request('GET', '/auto-hebergement');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Installez-la chez vous');
        $this->assertSelectorExists('a[href="'.SelfHostingPage::GUIDE.'"]');
        $this->assertSelectorExists('a[href="/ecoles-et-entreprises#demande"]');
        $this->assertSelectorExists('link[rel="canonical"][href="http://localhost/auto-hebergement"]');
        $this->assertSelectorNotExists('meta[name="robots"]');
        $this->assertSelectorExists('.site-footer a[href="/auto-hebergement"]');

        $client->request('GET', '/');
        $this->assertSelectorExists('.lp a[href="/auto-hebergement"]');
        $client->request('GET', '/sitemap.xml');
        $this->assertStringContainsString('<loc>http://localhost/auto-hebergement</loc>', (string) $client->getResponse()->getContent());
    }
}
