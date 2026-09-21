<?php

namespace App\Tests\Controller;

use App\Ai\ModelClient;
use App\Legal\LegalInfo;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/** Les pages légales décrivent l'instance (.env.test : une instance configurée, sans IA, inscription libre). */
final class LegalPagesTest extends WebTestCase
{
    use DatabaseTrait;

    public function testLesMentionsLegalesViennentDeLaConfiguration(): void
    {
        $client = static::createClient();
        $client->request('GET', '/mentions-legales');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#editeur', 'Exemple SAS');
        $this->assertSelectorTextContains('#editeur', 'RCS Lyon 123 456 789');
        $this->assertSelectorTextContains('#editeur', 'Ada Lovelace');
        $this->assertSelectorExists('#editeur a[href="mailto:contact@example.test"]');
        $this->assertSelectorTextContains('#hebergeur', 'Hébergeur Test');
        $this->assertSelectorTextContains('#hebergeur', '127.0.0.1', 'Le domaine du bac à sable vient de SANDBOX_ORIGIN.');
        $this->assertSelectorNotExists('.legal-missing');
        $this->assertSelectorNotExists('#editeur dt:contains("TVA")', 'Une valeur vide n\'est pas affichée.');
    }

    public function testLesCgvViennentDeLaConfiguration(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cgv');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('.legal-missing');
        $this->assertSelectorTextContains('#vendeur', 'Exemple SAS');
        $this->assertSelectorTextContains('#retractation', 'L221-28');
        $this->assertSelectorTextContains('#remboursement', 'pendant 14 jours');
        $this->assertSelectorTextContains('#mediation', 'Médiateur Test');
        $this->assertSelectorExists('#mediation a[href="https://mediateur.example.test"]');
        $this->assertSelectorExists('footer a[href="/cgv"]', 'Lien dans le pied de page.');

        $client->request('GET', '/confidentialite');
        $this->assertSelectorTextContains('#destinataires', 'Stripe', 'Paiement configuré : Stripe est un destinataire.');
        $this->assertSelectorTextContains('#conservation', 'Dix ans');
    }

    public function testUneInstanceNonConfigureeLeSignale(): void
    {
        $client = static::createClient();
        static::getContainer()->set(LegalInfo::class, new LegalInfo(hostName: 'Hébergeur Test'));
        $client->request('GET', '/mentions-legales');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.legal-missing', 'LEGAL_PUBLISHER_NAME');
        $this->assertSelectorTextNotContains('.legal-missing', 'LEGAL_HOST_NAME');
        $this->assertSelectorTextContains('#hebergeur', 'Hébergeur Test');
    }

    public function testLaPolitiqueDecritCeQueFaitLInstance(): void
    {
        $client = static::createClient();
        $client->request('GET', '/confidentialite');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#responsable', 'Exemple SAS');
        $this->assertSelectorExists('#droits a[href="mailto:contact@example.test"]');
        $this->assertSelectorTextContains('#destinataires', 'Hébergeur Test');
        $this->assertSelectorTextNotContains('#destinataires', 'Anthropic', 'Sans clé d\'API, le mentor n\'existe pas : on ne cite pas Anthropic.');
        $this->assertSelectorTextNotContains('#donnees', 'liste d\'attente', 'Inscription libre : pas de liste d\'attente.');
        $this->assertSelectorTextContains('#cookies', 'localStorage');
    }

    public function testAvecLeMentorLaPolitiqueCiteAnthropic(): void
    {
        $client = static::createClient();
        static::getContainer()->set(ModelClient::class, new ModelClient(new MockHttpClient(), 'cle-de-test', 'claude-sonnet-5'));
        $client->request('GET', '/confidentialite');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#destinataires', 'Anthropic');
        $this->assertSelectorTextContains('#donnees', 'mentor');
        $this->assertSelectorTextContains('#conservation', 'mentor');
    }

    public function testLesPagesSontAccessiblesDepuisLeSite(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');
        $this->assertSelectorExists('.site-footer a[href="/mentions-legales"]');
        $this->assertSelectorExists('.site-footer a[href="/confidentialite"]');
        $this->assertSelectorCount(1, 'footer', 'Le même pied de page que le reste du site, une seule fois.');

        $client->request('GET', '/inscription');
        $this->assertSelectorExists('form a[href="/confidentialite"]', 'Informé au moment où l\'adresse est demandée.');
        $this->assertSelectorExists('.site-footer a[href="/mentions-legales"]');

        $this->resetDatabase();
        $client->loginUser($this->createUser());
        $client->request('GET', '/parcours/decouverte/01-bonjour');
        $this->assertSelectorNotExists('.site-footer', 'Le playground est en plein écran.');
    }

    public function testSecurityTxtDonneLeContactDeLEditeur(): void
    {
        $client = static::createClient();
        $client->request('GET', '/.well-known/security.txt');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');
        $body = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString("Contact: mailto:contact@example.test\n", $body);
        $this->assertMatchesRegularExpression('/^Expires: (\d{4}-\d{2}-\d{2})T00:00:00Z$/m', $body);
        preg_match('/^Expires: (\S+)$/m', $body, $expires);
        $this->assertGreaterThan(new \DateTimeImmutable('+11 months'), new \DateTimeImmutable($expires[1]), 'Toujours valable près d\'un an.');
        $this->assertStringContainsString('Canonical: http://localhost/.well-known/security.txt', $body);
    }

    public function testSansAdresseDeContactPasDeSecurityTxt(): void
    {
        $client = static::createClient();
        static::getContainer()->set(LegalInfo::class, new LegalInfo(hostName: 'Hébergeur Test'));
        $client->request('GET', '/.well-known/security.txt');

        $this->assertResponseStatusCodeSame(404);
    }
}
