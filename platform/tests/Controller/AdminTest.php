<?php

namespace App\Tests\Controller;

use App\Api\FeedbackInput;
use App\Content\ContentRepository;
use App\Entity\User;
use App\Repository\CohortRepository;
use App\Repository\FeedbackRepository;
use App\Service\FeedbackService;
use App\Service\ProgressService;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminTest extends WebTestCase
{
    use DatabaseTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    private function loginAsAdmin(): User
    {
        $admin = $this->createUser('admin@example.test', 'Admin');
        $admin->setRoles([User::ROLE_ADMIN]);
        static::getContainer()->get('doctrine')->getManager()->flush();
        $this->client->loginUser($admin);

        return $admin;
    }

    public function testLeTableauDeBordEstReserveAuxAdmins(): void
    {
        $this->client->request('GET', '/admin');
        $this->assertResponseRedirects('/connexion', message: 'Un anonyme est envoyé à la connexion.');

        $this->client->loginUser($this->createUser());
        $this->client->request('GET', '/admin');
        $this->assertResponseStatusCodeSame(403, 'Un apprenant n\'a pas accès.');
    }

    public function testLaPageApprenantsFonctionneSansAucuneCohorte(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/admin/apprenants');
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/admin/apprenants/render-filters');
        $this->assertResponseIsSuccessful('Le filtre « Cohorte » se rend même sans cohorte.');
    }

    public function testCreerUneCohorteDepuisLAdminDonneUnLienDInvitation(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/admin/cohortes/new');
        $this->assertResponseIsSuccessful();
        $this->client->submitForm('Créer', [
            'Cohort[name]' => 'BUT Info Annecy 2026',
            'Cohort[code]' => 'IUT-Annecy-2026',
            // Financée par l'établissement (par défaut) : la cohorte choisit ses parcours.
            'Cohort[availableTrackIds]' => ['decouverte'],
        ], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseRedirects();

        $cohort = static::getContainer()->get(CohortRepository::class)->findOneBy(['name' => 'BUT Info Annecy 2026']);
        $this->assertNotNull($cohort);
        $this->assertMatchesRegularExpression('/^iut-annecy-2026-[a-z2-9]{10}$/', (string) $cohort->getCode(), 'Le code est normalisé en minuscules, et reçoit une partie aléatoire : il ne se devine pas.');
        $this->assertTrue($cohort->isActive());
        $code = (string) $cohort->getCode();

        $this->client->request('GET', '/admin/cohortes');
        $this->assertSelectorTextContains('body', 'http://localhost/inscription?code='.$code, 'Le lien d\'invitation est affiché.');

        $this->client->request('GET', '/admin');
        $this->assertSelectorTextContains('h2', 'BUT Info Annecy 2026', 'Une cohorte vide apparaît quand même sur l\'accueil.');
        $this->client->request('GET', '/admin/cohorte/'.$code);
        $this->assertResponseIsSuccessful();
    }

    public function testCohortesProgressionEtGrille(): void
    {
        $container = static::getContainer();
        $this->loginAsAdmin();
        $ada = $this->createUser('ada@example.test', 'Ada', 'iut-2026');
        $first = $container->get(ContentRepository::class)->findExercise('decouverte', '01-bonjour');
        $container->get(ProgressService::class)->complete($ada, $first, 1);

        $crawler = $this->client->request('GET', '/admin');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'iut-2026');
        $possible = \count($container->get(ContentRepository::class)->findTrack('decouverte')->exerciseIds());
        $this->assertStringContainsString('1 / '.$possible, $crawler->filter('table')->first()->text(), 'Un exercice réussi sur tous ceux du parcours (une seule apprenante dans la cohorte).');

        $crawler = $this->client->click($crawler->selectLink('iut-2026')->link());
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Ada', $crawler->filter('tbody')->text());
        $this->assertSame(['✓ 1'], array_map(trim(...), $crawler->filter('tbody .badge-success')->extract(['_text'])), 'Réussi avec un indice.');

        $this->client->request('GET', '/admin/cohorte/inconnue');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testListesEtMarquageDUnRetourTraite(): void
    {
        $container = static::getContainer();
        $this->loginAsAdmin();
        $ada = $this->createUser('ada@example.test', 'Ada', 'iut-2026');
        $exercise = $container->get(ContentRepository::class)->findExercise('decouverte', '01-bonjour');
        $progress = $container->get(ProgressService::class)->saveDraft($ada, $exercise, [], 2);
        $feedback = $container->get(FeedbackService::class)->record($ada, $exercise, new FeedbackInput('unclear', 'Où va le contrôleur ?'));

        foreach (['/admin/apprenants', '/admin/apprenants/'.$ada->getId(), '/admin/progression', '/admin/progression/'.$progress->getId(), '/admin/retours', '/admin/retours/'.$feedback->getId()] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful($url);
        }
        $this->assertSelectorTextContains('body', 'Où va le contrôleur ?');
        $crawler = $this->client->request('GET', '/admin/apprenants/'.$ada->getId());
        $this->assertSelectorTextContains('.learner-progress', 'Découverte', 'La fiche montre l\'avancement par parcours.');
        $this->assertCount(1, $crawler->filter('.learner-progress .exercise-chip[data-state="in_progress"]'), 'Le brouillon apparaît en cours.');
        $this->client->request('GET', '/admin/progression');
        $this->assertSelectorTextContains('body', 'En cours', 'Statut en français.');
        $this->client->request('GET', '/admin/retours');
        $this->assertSelectorTextContains('body', 'Pas clair', 'Type en français.');
        $this->client->request('GET', '/admin/retours/'.$feedback->getId());
        $this->assertSelectorTextContains('body', 'Où va le contrôleur ?');

        $this->client->request('GET', '/admin/retours/'.$feedback->getId().'/traite');
        $this->assertResponseRedirects();
        $this->assertTrue($container->get(FeedbackRepository::class)->find($feedback->getId())->isHandled());

        $this->client->request('GET', '/admin/retours/'.$feedback->getId().'/rouvrir');
        $this->assertResponseRedirects();
        $this->assertFalse($container->get(FeedbackRepository::class)->find($feedback->getId())->isHandled());
    }

    public function testSupprimerUnApprenantEmporteSaProgressionEtSesRetours(): void
    {
        $container = static::getContainer();
        $admin = $this->loginAsAdmin();
        $ada = $this->createUser('ada@example.test', 'Ada', 'iut-2026');
        $exercise = $container->get(ContentRepository::class)->findExercise('decouverte', '01-bonjour');
        $container->get(ProgressService::class)->complete($ada, $exercise, 0);
        $container->get(FeedbackService::class)->record($ada, $exercise, new FeedbackInput('bug', 'Cassé'));

        // Les requêtes HTTP redémarrent le kernel : on les fait après les écritures, et on relit Ada par son id.
        $this->client->request('GET', '/admin/apprenants/'.$admin->getId());
        $this->assertSelectorNotExists('a.action-delete', 'Un admin ne peut pas supprimer son propre compte.');
        $this->client->request('GET', '/admin/apprenants/'.$ada->getId());
        $this->assertSelectorExists('a.action-delete');

        // Comme dans le tableau de bord : l'apprenant est relu en base avant d'être supprimé.
        $entityManager = $container->get('doctrine')->getManager();
        $entityManager->clear();
        $entityManager->remove($entityManager->find(User::class, $ada->getId()));
        $entityManager->flush();

        $this->assertSame([], $container->get(FeedbackRepository::class)->findAll());
        $this->assertSame(0, (int) $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM exercise_progress'), 'La progression suit la suppression du compte.');
    }
}
