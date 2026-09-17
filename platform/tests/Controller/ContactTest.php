<?php

namespace App\Tests\Controller;

use App\Entity\ContactMessage;
use App\Entity\ContactSubject;
use App\Legal\LegalInfo;
use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Page de contact et page Écoles et entreprises (.env.test : CONTACT_EMAIL vide, l'éditeur est contact@example.test). */
final class ContactTest extends WebTestCase
{
    use DatabaseTrait;

    private const array ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];

    /** @return list<ContactMessage> */
    private function messages(): array
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(ContactMessage::class)->findAll();
    }

    public function testUnMessageEstGardeEtTransmisALEquipe(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/contact?objet=commande');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select#contact_form_subject option[value="commande"][selected]', 'L\'objet se choisit par l\'adresse.');
        $client->submitForm('Envoyer le message', [
            'contact_form[name]' => 'Gorm',
            'contact_form[email]' => 'gorm@example.test',
            'contact_form[message]' => 'Je voudrais une facture au nom de mon entreprise.',
        ], serverParameters: self::ORIGIN);

        $this->assertResponseRedirects('/contact', 303);
        $messages = $this->messages();
        $this->assertCount(1, $messages);
        $this->assertSame(ContactSubject::Order, $messages[0]->getSubject());
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertEmailAddressContains($email, 'to', 'contact@example.test');
        $this->assertEmailAddressContains($email, 'reply-to', 'gorm@example.test');
        $this->assertEmailTextBodyContains($email, 'facture au nom de mon entreprise');

        $client->followRedirect();
        $this->assertSelectorTextContains('.flash', 'Message envoyé');
    }

    public function testUnMessageIncompletEstRefuse(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/contact');
        $client->submitForm('Envoyer le message', [
            'contact_form[name]' => 'Gorm',
            'contact_form[email]' => 'pas-une-adresse',
            'contact_form[message]' => 'Court',
        ], serverParameters: self::ORIGIN);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('main', 'Cet email n\'est pas valide.');
        $this->assertSelectorTextContains('main', 'Au moins 10 caractères');
        $this->assertCount(0, $this->messages());
        $this->assertEmailCount(0);
    }

    public function testUnRobotNEnvoieRien(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/contact');
        $client->submitForm('Envoyer le message', [
            'contact_form[name]' => 'Robot',
            'contact_form[email]' => 'robot@example.test',
            'contact_form[message]' => 'Achetez mes lunettes de soleil.',
            'contact_form[site]' => 'https://spam.example.test',
        ], serverParameters: self::ORIGIN);

        $this->assertResponseRedirects('/contact', 303, 'Il croit avoir réussi.');
        $this->assertCount(0, $this->messages());
        $this->assertEmailCount(0);
    }

    public function testUnConnecteRetrouveSesCoordonnees(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $client->loginUser($this->createUser());

        $client->request('GET', '/contact');
        $this->assertInputValueSame('contact_form[name]', 'Ada');
        $this->assertInputValueSame('contact_form[email]', 'ada@example.test');
        $client->submitForm('Envoyer le message', ['contact_form[message]' => 'Merci pour le parcours, vraiment.'], serverParameters: self::ORIGIN);

        $this->assertResponseRedirects('/contact', 303);
        $this->assertSame('ada@example.test', $this->messages()[0]->getUser()?->getEmail());
    }

    public function testUneEcoleDemandeUnDevis(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/ecoles-et-entreprises');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Faites coder votre groupe');
        $this->assertSelectorNotExists('#contact_form_subject', 'L\'objet est fixé.');
        $client->submitForm('Envoyer la demande', [
            'contact_form[name]' => 'Mme Lovelace',
            'contact_form[email]' => 'ada@iut.example.test',
            'contact_form[organization]' => 'IUT de Bordeaux',
            'contact_form[headcount]' => '28',
            'contact_form[message]' => 'Une promotion de BUT 2, Symfony au second semestre.',
        ], serverParameters: self::ORIGIN);

        $this->assertResponseRedirects('/ecoles-et-entreprises#demande', 303);
        $message = $this->messages()[0];
        $this->assertSame(ContactSubject::Organization, $message->getSubject());
        $this->assertSame('IUT de Bordeaux', $message->getOrganization());
        $this->assertSame(28, $message->getHeadcount());
        $this->assertEmailSubjectContains($this->getMailerMessage(), 'IUT de Bordeaux');
    }

    public function testTropDeMessagesDepuisLaMemeAdresse(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        // Les compteurs vivent dans un cache en mémoire, vidé entre deux requêtes de test : on épuise le quota avant
        // la première requête, sur le même kernel.
        $limiter = static::getContainer()->get('limiter.contact')->create('127.0.0.1');
        for ($i = 1; $i <= 5; ++$i) {
            $limiter->consume();
        }

        $client->request('POST', '/contact', ['contact_form' => [
            'subject' => 'question',
            'name' => 'Gorm',
            'email' => 'gorm@example.test',
            'message' => 'Un sixième message dans l\'heure.',
            '_token' => 'csrf-token',
        ]], server: self::ORIGIN);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('main', 'réessayez dans une heure');
        $this->assertCount(0, $this->messages());
    }

    public function testSansAdressePasDeFormulaire(): void
    {
        $client = static::createClient();
        static::getContainer()->set(LegalInfo::class, new LegalInfo(hostName: 'Hébergeur Test'));
        $client->request('GET', '/contact');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('form[name="contact_form"]');
        $this->assertSelectorTextContains('main', 'mentions légales');
    }

    public function testLesPagesSontLieesEtReferencees(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');
        $this->assertSelectorExists('.site-footer a[href="/contact"]');
        $this->assertSelectorExists('.lp-audience a[href="/ecoles-et-entreprises"]');
        $client->request('GET', '/mentions-legales');
        $this->assertSelectorExists('.site-footer a[href="/ecoles-et-entreprises"]');

        $client->request('GET', '/ecoles-et-entreprises');
        $this->assertSelectorExists('link[rel="canonical"][href="http://localhost/ecoles-et-entreprises"]');
        $this->assertSelectorNotExists('meta[name="robots"]');
        $client->request('GET', '/sitemap.xml');
        $this->assertStringContainsString('<loc>http://localhost/ecoles-et-entreprises</loc>', (string) $client->getResponse()->getContent());
        $this->assertStringContainsString('<loc>http://localhost/contact</loc>', (string) $client->getResponse()->getContent());
    }
}
