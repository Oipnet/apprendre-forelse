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

    /**
     * Presque toute inscription suit la lecture d'un exercice : la démo passe quand même avant, puis propose de le
     * reprendre (dans la barre de guidage, et après la réussite).
     */
    public function testDepuisUnExerciceLaDemoPasseAvantPuisProposeDeLeReprendre(): void
    {
        $client = $this->client();

        $this->register($client, '/inscription?suite=/parcours/premiers-pas/suite', 'Créer mon compte');

        $this->assertResponseRedirects('/parcours/premiers-pas/pancarte?reprendre=%2Fparcours%2Fpremiers-pas%2Fsuite');
        $crawler = $client->followRedirect();
        $config = json_decode((string) $crawler->filter('[data-playground]')->attr('data-config'), true);
        $this->assertSame(['title' => 'La suite', 'url' => '/parcours/premiers-pas/suite'], $config['resume']);
        $payload = $this->json($client, 'GET', '/api/exercises/premiers-pas/suite');
        $this->assertNull($payload['guide'], 'Un exercice sans guidage n\'en a pas.');
    }

    /** Une page où revenir qui n'est pas un exercice (« Acheter », une page protégée) : elle tout de suite. */
    public function testUnePageQuiNEstPasUnExercicePasseAvantLaDemo(): void
    {
        $client = $this->client();

        $this->register($client, '/inscription?suite=/parcours/premiers-pas', 'Créer mon compte');

        $this->assertResponseRedirects('/parcours/premiers-pas');
    }

    /** `?reprendre=` qui ne mène pas à un exercice (ou mène ailleurs) : la démo n'a rien à proposer. */
    public function testUnReprendreQuiNEstPasUnExerciceEstIgnore(): void
    {
        $client = $this->client();
        $this->register($client, '/inscription', 'Créer mon compte');

        foreach (['/parcours/premiers-pas', '//ailleurs.example/x', '/parcours/premiers-pas/inconnu'] as $reprendre) {
            $crawler = $client->request('GET', '/parcours/premiers-pas/pancarte?reprendre='.rawurlencode($reprendre));
            $config = json_decode((string) $crawler->filter('[data-playground]')->attr('data-config'), true);
            $this->assertNull($config['resume'], $reprendre);
        }
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

    /** Le lien sans ?suite= d'un exercice : le Referer suffit pour le retrouver après la démo. */
    public function testDepuisUnExerciceLeRefererSuffit(): void
    {
        $client = $this->client();

        $this->register($client, '/inscription', 'Créer mon compte', referer: 'http://localhost/parcours/premiers-pas/suite');

        $this->assertResponseRedirects('/parcours/premiers-pas/pancarte?reprendre=%2Fparcours%2Fpremiers-pas%2Fsuite');
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
