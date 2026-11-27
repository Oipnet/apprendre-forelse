<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Un nouveau compte sans page où revenir commence par l'exercice d'accueil (`welcome: true`), guidé pas à pas :
 * sur l'accueil, les inscrits ne savaient pas par où commencer.
 */
final class WelcomeExerciseTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }

    public function testSansPageOuRevenirLInscritArriveSurLExerciceDAccueil(): void
    {
        $client = $this->client();

        $this->register($client, '/inscription', 'Créer mon compte');

        // L'exercice marqué, pas le premier du parcours.
        $this->assertResponseRedirects('/parcours/premiers-pas/pancarte');
        $client->followRedirect();
        $this->assertResponseIsSuccessful('Le premier chapitre est ouvert à tout compte.');
        $payload = $this->json($client, 'GET', '/api/exercises/premiers-pas/pancarte');
        $this->assertSame("Remplacez `'Fermé'` par `'Ouvert'`.", $payload['guide'], 'Le playground reçoit le guidage.');
    }

    public function testEnAnglaisLExerciceDAccueilEstSaTraduction(): void
    {
        $client = $this->client();

        $this->register($client, '/en/signup', 'Create my account');

        $this->assertResponseRedirects('/en/tracks/premiers-pas/pancarte');
        $payload = $this->json($client, 'GET', '/en/api/exercises/premiers-pas/pancarte');
        $this->assertSame("Replace `'Closed'` with `'Open'`.", $payload['guide']);
    }

    public function testLaPageOuRevenirPasseAvantLExerciceDAccueil(): void
    {
        $client = $this->client();

        $this->register($client, '/inscription?suite=/parcours/premiers-pas/suite', 'Créer mon compte');

        $this->assertResponseRedirects('/parcours/premiers-pas/suite');
        $payload = $this->json($client, 'GET', '/api/exercises/premiers-pas/suite');
        $this->assertNull($payload['guide'], 'Un exercice sans guidage n\'en a pas.');
    }

    /**
     * Le bouton « Créer un compte » de l'en-tête, sur une page qui n'est pas un exercice (la page d'un parcours, un
     * article) : le Referer n'est pas une page où revenir, le nouveau compte commence par l'exercice d'accueil.
     */
    public function testDepuisUnePageQuiNEstPasUnExerciceLInscritArriveSurLExerciceDAccueil(): void
    {
        $client = $this->client();

        $this->register($client, '/inscription', 'Créer mon compte', referer: 'http://localhost/parcours/premiers-pas');

        $this->assertResponseRedirects('/parcours/premiers-pas/pancarte');
    }

    /** Le lien sans ?suite= d'un exercice : le Referer suffit pour y revenir. */
    public function testDepuisUnExerciceLInscritYRevient(): void
    {
        $client = $this->client();

        $this->register($client, '/inscription', 'Créer mon compte', referer: 'http://localhost/parcours/premiers-pas/suite');

        $this->assertResponseRedirects('/parcours/premiers-pas/suite');
    }

    /** Un Referer d'un autre site (un lien posté ailleurs vers /inscription) n'est pas une page où revenir. */
    public function testUnRefererDUnAutreSiteEstIgnore(): void
    {
        $client = $this->client();

        $this->register($client, '/inscription', 'Créer mon compte', referer: 'https://ailleurs.example/parcours/premiers-pas/suite');

        $this->assertResponseRedirects('/parcours/premiers-pas/pancarte');
    }

    private function client(): KernelBrowser
    {
        $this->usePacks(__DIR__.'/../Fixtures/packs/accueil');
        $client = static::createClient();
        $this->resetDatabase();

        return $client;
    }

    private function register(KernelBrowser $client, string $url, string $button, ?string $referer = null): void
    {
        $client->request('GET', $url, server: null === $referer ? [] : ['HTTP_REFERER' => $referer]);
        $client->submitForm($button, [
            'registration_form[displayName]' => 'Gorm',
            'registration_form[email]' => 'gorm@example.test',
            'registration_form[plainPassword]' => 'une-longue-phrase',
        ], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);
    }
}
