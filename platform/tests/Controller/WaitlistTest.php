<?php

namespace App\Tests\Controller;

use App\Entity\WaitlistEntry;
use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** Liste d'attente de la page d'accueil, proposée en bêta fermée (REGISTRATION_INVITE_ONLY). */
final class WaitlistTest extends WebTestCase
{
    use DatabaseTrait;

    /** @var array<string, string|null> valeurs d'origine de REGISTRATION_INVITE_ONLY ($_ENV, $_SERVER) */
    private array $originalInviteOnly = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['_ENV', '_SERVER'] as $store) {
            $this->originalInviteOnly[$store] = $GLOBALS[$store]['REGISTRATION_INVITE_ONLY'] ?? null;
            $GLOBALS[$store]['REGISTRATION_INVITE_ONLY'] = '1';
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalInviteOnly as $store => $value) {
            if (null === $value) {
                unset($GLOBALS[$store]['REGISTRATION_INVITE_ONLY']);
            } else {
                $GLOBALS[$store]['REGISTRATION_INVITE_ONLY'] = $value;
            }
        }
        parent::tearDown();
    }

    public function testUneAdresseEstEnregistreeUneSeuleFois(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $crawler = $this->submit($client, '  Ada@Example.test ');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#liste-attente .lp-notice.ok', 'C\'est noté');
        $this->assertSelectorNotExists('form.lp-form', 'Une fois inscrit, le formulaire laisse place à la confirmation.');

        $this->submit($client, 'ada@example.test');

        $entries = static::getContainer()->get(EntityManagerInterface::class)->getRepository(WaitlistEntry::class)->findAll();
        $this->assertCount(1, $entries);
        $this->assertSame('ada@example.test', $entries[0]->getEmail(), 'Adresse normalisée : minuscules, sans espaces.');
        $this->assertNotNull($crawler);
    }

    public function testUneAdresseInvalideEstRefusee(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $this->submit($client, 'pas-une-adresse');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#liste-attente .lp-notice.ko', 'ne semble pas valide');
        $this->assertSelectorExists('form.lp-form', 'Le formulaire reste affiché pour corriger.');
        $this->assertCount(0, static::getContainer()->get(EntityManagerInterface::class)->getRepository(WaitlistEntry::class)->findAll());
    }

    public function testUnRobotQuiRemplitLeChampPiegeNEstPasEnregistre(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $this->submit($client, 'robot@example.test', 'https://spam.example');

        $this->assertSelectorTextContains('#liste-attente .lp-notice.ok', 'C\'est noté', 'Le robot croit avoir réussi…');
        $this->assertCount(0, static::getContainer()->get(EntityManagerInterface::class)->getRepository(WaitlistEntry::class)->findAll(), '… mais rien n\'est enregistré.');
    }

    public function testSansJetonCsrf(): void
    {
        $client = static::createClient();
        $client->request('POST', '/liste-d-attente', ['email' => 'ada@example.test']);

        $this->assertResponseStatusCodeSame(403);
    }

    /** Soumet le formulaire de la page d'accueil (jeton CSRF compris) et suit la redirection. */
    private function submit(KernelBrowser $client, string $email, string $trap = ''): Crawler
    {
        $crawler = $client->request('GET', '/');
        $this->assertSelectorExists('form.lp-form', 'Bêta fermée : la page propose la liste d\'attente.');
        $form = $crawler->filter('form.lp-form')->form();
        $form['email'] = $email;
        $form['site'] = $trap;
        $client->submit($form);

        $this->assertResponseRedirects();

        return $client->followRedirect();
    }
}
