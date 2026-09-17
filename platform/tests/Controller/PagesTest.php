<?php

namespace App\Tests\Controller;

use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Service\ProgressService;
use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use App\Version;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PagesTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }

    public function testAccueilListeLesParcoursDesPacks(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.lp-parcours', 'Découverte');
        $this->assertSelectorTextContains('.lp-chapters li:first-child', 'Bonjour Symfony');
        $this->assertSelectorExists('.lp-hero a.primary[href="/parcours/decouverte/01-bonjour"]', 'Le bouton principal mène au premier exercice, jouable sans compte.');
        $this->assertSelectorExists('#liste-attente a[href="/inscription"]', 'Inscription libre : pas de liste d\'attente.');
        $this->assertSelectorNotExists('form.lp-form');
    }

    public function testLeMenuDuCompteRegroupeLesEspacesReserves(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $client->loginUser($this->createUser('ada@example.test', 'Ada'));
        $client->request('GET', '/pratique');
        $this->assertSelectorTextContains('.lp-header details.lp-account summary', 'Ada');
        $this->assertSelectorExists('.lp-account-menu a[href="/compte"]');
        $this->assertSelectorExists('.lp-account-menu a[href="/deconnexion"]');
        $this->assertSelectorNotExists('.lp-account-label', 'Un apprenant n\'a pas d\'espace réservé.');

        $admin = $this->createUser('admin@example.test', 'Admin')->setRoles([\App\Entity\User::ROLE_ADMIN]);
        static::getContainer()->get('doctrine')->getManager()->flush();
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/pratique');
        $this->assertSame(['Parcours', 'Pratique', 'Questions'], $crawler->filter('.lp-header nav > a')->each(fn ($a) => $a->text()), 'Au premier niveau, la navigation du site seulement.');
        $this->assertSame(['Mon compte', 'Atelier', 'Mes cohortes', 'Administration', 'Déconnexion'], $crawler->filter('.lp-account-menu a')->each(fn ($a) => $a->text()));
    }

    /** Un lien vers une route absente casse la page en 500 : on la rend, tout simplement. */
    public function testLesPagesDeConnexionEtDInscriptionSAffichent(): void
    {
        $client = static::createClient();
        $client->request('GET', '/connexion');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form input[name="password"]');

        $client->request('GET', '/inscription');
        $this->assertResponseIsSuccessful();
    }

    public function testCarteDuParcours(): void
    {
        $client = static::createClient();
        $client->request('GET', '/parcours/decouverte');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorCount(2, '.exercises li[data-state]', 'Deux exercices, plus la fiche de cours.');
        $this->assertSelectorTextContains('.chapter h2 .tag', 'Gratuit, sans compte', 'Le premier chapitre est libre.');
        // L'infobulle liste les notions abordées par l'exercice, reliée au lien pour les lecteurs d'écran.
        $this->assertSelectorExists('.exercises li:first-child a[aria-describedby="notions-01-bonjour"] #notions-01-bonjour[role="tooltip"]');
        $this->assertSelectorTextContains('.exercises li:first-child .hint', 'Notions abordées');
        $this->assertSelectorTextContains('.exercises li:first-child .hint .tag', 'Route');
    }

    public function testUnInviteJoueUnExerciceLibre(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/parcours/decouverte/01-bonjour');

        $this->assertResponseIsSuccessful();
        $config = json_decode($crawler->filter('[data-playground]')->attr('data-config'), true);
        $this->assertSame('local', $config['progress']['mode']);
        $this->assertSame('http://127.0.0.1:8001/sandbox', $config['sandboxUrl']);
        $this->assertSame('/inscription?suite=/parcours/decouverte/02-bonjour-prenom', urldecode($config['registerUrl']));
        $this->assertNull($config['user'], 'Un invité n\'a pas de compte à afficher…');
        $this->assertSame('/connexion', $config['loginUrl'], '… mais le playground lui propose de se connecter.');
    }

    public function testUnInviteLitLaConsigneDUnExerciceFermeSansEditeur(): void
    {
        $this->usePaidPack();
        $client = static::createClient();
        $client->request('GET', '/parcours/payant/e2');

        $this->assertResponseIsSuccessful('La page de l\'exercice est publique…');
        $this->assertSelectorCount(1, 'h1');
        $this->assertSelectorExists('.exercise-instructions');
        $this->assertSelectorExists('.breadcrumb a[href="/parcours/payant"]');
        $this->assertSelectorNotExists('[data-playground]', '… mais ni l\'éditeur…');
        $this->assertStringNotContainsString('build/assets/playground', (string) $client->getResponse()->getContent(), '… ni le moteur WebAssembly.');
        $this->assertSelectorExists('.exercise-access a[href="/inscription?suite=/parcours/payant/e2"]');
        $this->assertSelectorExists('.exercise-access a[href="/parcours/payant/e1"]', 'Le premier chapitre, libre, est proposé.');
        $client->request('GET', '/parcours/payant/e1');
        $this->assertResponseIsSuccessful('Tout le premier chapitre se joue sans compte.');
    }

    public function testEnBetaFermeeLInviteSansCodeEstOrienteVersLaListeDAttente(): void
    {
        $original = [$_ENV['REGISTRATION_INVITE_ONLY'] ?? null, $_SERVER['REGISTRATION_INVITE_ONLY'] ?? null];
        $_ENV['REGISTRATION_INVITE_ONLY'] = $_SERVER['REGISTRATION_INVITE_ONLY'] = '1';
        try {
            $this->usePaidPack();
            $client = static::createClient();
            $client->request('GET', '/parcours/payant/e2');
            $this->assertResponseIsSuccessful();
            $this->assertSelectorTextContains('.exercise-access', 'liste d\'attente', 'Le message ne promet pas un compte « gratuit » que l\'invité sans code ne peut pas créer.');
            $this->assertSelectorExists('.exercise-access a[href="/#liste-attente"]');

            $crawler = $client->request('GET', '/parcours/payant/e1');
            $config = json_decode($crawler->filter('[data-playground]')->attr('data-config'), true);
            $this->assertSame('/#liste-attente', $config['waitlistUrl'], 'Le playground propose la liste d\'attente à la fin de l\'exercice libre.');
        } finally {
            [$_ENV['REGISTRATION_INVITE_ONLY'], $_SERVER['REGISTRATION_INVITE_ONLY']] = $original;
        }
    }

    public function testUnApprenantConnecteSauvegardeSurLeServeur(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $client->loginUser($this->createUser());
        $crawler = $client->request('GET', '/parcours/decouverte/02-bonjour-prenom');

        $this->assertResponseIsSuccessful();
        $config = json_decode($crawler->filter('[data-playground]')->attr('data-config'), true);
        $this->assertSame(['mode' => 'api', 'url' => '/api/progress/decouverte/02-bonjour-prenom'], $config['progress']);
        $this->assertSame(['name' => 'Ada', 'xp' => 0], $config['user'], 'Le playground affiche qui est connecté (la page n\'a pas l\'en-tête du site).');
    }

    /** Packs du moteur + un pack de test dont le parcours « debut » conseille « suite ». */
    private function avecParcoursEnchaines(): void
    {
        static::getContainer()->set(ContentRepository::class, new ContentRepository(
            [__DIR__.'/../../../examples/packs', __DIR__.'/../Fixtures/packs'],
            static::getContainer()->get(EnvironmentRegistry::class),
            new Version(__DIR__.'/../../../VERSION'),
        ));
    }

    public function testLaCarteDuParcoursConseilleLaSuite(): void
    {
        $client = static::createClient();
        $this->avecParcoursEnchaines();
        $client->request('GET', '/parcours/debut');

        $this->assertSelectorTextContains('.next-track', 'La suite');
        $this->assertSelectorExists('.next-track a[href="/parcours/suite"]');
        $this->assertSelectorNotExists('.track-done', 'Parcours pas encore terminé : pas de bannière.');

        $client->request('GET', '/parcours/decouverte');
        $this->assertSelectorNotExists('.next-track', 'Le parcours conseillé n\'est pas installé : rien à proposer.');
    }

    public function testUnParcoursTermineInviteALaSuite(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $this->avecParcoursEnchaines();
        $user = $this->createUser();
        $content = static::getContainer()->get(ContentRepository::class);
        static::getContainer()->get(ProgressService::class)->complete($user, $content->findExercise('debut', 'e1'), 0);
        $client->loginUser($user);
        $client->request('GET', '/parcours/debut');

        $this->assertSelectorTextContains('.track-done', 'Parcours terminé');
        $this->assertSelectorExists('.track-done a[href="/parcours/suite"]');
    }

    public function testExerciceInconnu(): void
    {
        $client = static::createClient();
        $client->request('GET', '/parcours/decouverte/inexistant');

        $this->assertResponseStatusCodeSame(404);
    }
}
