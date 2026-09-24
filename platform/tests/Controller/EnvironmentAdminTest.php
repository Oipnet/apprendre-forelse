<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Instance\EnvironmentBuildQueue;
use App\Instance\InstalledEnvironments;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * La page d'administration des environnements d'exécution.
 *
 * Elle lance des installations qui clonent puis exécutent du code : ce qui est vérifié ici, ce sont les
 * portes — qui entre, et ce qui est refusé avant qu'un processus ne démarre.
 */
final class EnvironmentAdminTest extends WebTestCase
{
    use DatabaseTrait;

    private KernelBrowser $client;
    private string $installes;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
        $this->filesystem = new Filesystem();
        $this->installes = static::getContainer()->get(InstalledEnvironments::class)->directory();
        // Rien ne crée ce dossier : ni l'installation, ni la CI sur un dépôt fraîchement cloné. Sans lui
        // la fonctionnalité se croit absente et les portes vérifiées ici ne s'affichent pas.
        $this->filesystem->mkdir($this->installes);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->filesystem->remove($this->installes.'/'.InstalledEnvironments::JOBS);
        $this->filesystem->remove($this->installes.'/'.EnvironmentBuildQueue::DIRECTORY);
        // Remis à la valeur de .env, et non retiré : une variable absente ferait échouer les tests suivants.
        foreach (['_ENV', '_SERVER'] as $store) {
            $GLOBALS[$store]['ENVIRONMENTS_BUILDER'] = '';
        }
    }

    /** Avec le service empaqueteur, la plateforme n'exécute rien : elle dépose la demande dans le volume partagé. */
    public function testAvecLEmpaqueteurLaDemandeEstDeposeeSansRienLancerIci(): void
    {
        foreach (['_ENV', '_SERVER'] as $store) {
            $GLOBALS[$store]['ENVIRONMENTS_BUILDER'] = EnvironmentBuildQueue::BUILDER;
        }
        $this->connecteUnAdmin();
        $this->client->request('GET', '/admin/environnements');
        $this->client->submitForm('Installer', ['depot' => 'https://exemple.test/depot.git', 'ref' => 'v1']);

        $this->assertResponseRedirects();
        $this->assertSame([], static::getContainer()->get(InstalledEnvironments::class)->jobs(), 'Aucune installation lancée dans ce conteneur.');
        $this->assertSame(
            ['command' => 'app:environnement:installer', 'arguments' => ['https://exemple.test/depot.git', '--ref=v1']],
            static::getContainer()->get(EnvironmentBuildQueue::class)->pop(),
        );
    }

    public function testLaPageEstReserveeAuxAdmins(): void
    {
        $this->client->request('GET', '/admin/environnements');
        $this->assertResponseRedirects('/connexion', message: 'Un anonyme est envoyé à la connexion.');

        $this->client->loginUser($this->createUser());
        $this->client->request('GET', '/admin/environnements');
        $this->assertResponseStatusCodeSame(403, 'Un apprenant n\'a pas accès.');
    }

    public function testLaPageListeLesEnvironnementsDuMoteur(): void
    {
        $this->connecteUnAdmin();
        $crawler = $this->client->request('GET', '/admin/environnements');

        $this->assertResponseIsSuccessful();
        $page = $crawler->filter('body')->text();
        $this->assertStringContainsString('symfony-8', $page);
        $this->assertStringContainsString('livré avec le moteur', $page, 'Un environnement du moteur n\'a pas de dépôt d\'origine.');
        // Les environnements composés se signalent : c'est ce qui explique qu'ils tiennent en dix fichiers.
        $this->assertStringContainsString('composé', $page);
    }

    /** Le formulaire n'apparaît que si l'instance a de quoi installer ; sinon la page dit quoi monter. */
    public function testSansDossierInstallableLaPageExpliqueAuLieuDOffrirLeFormulaire(): void
    {
        $this->connecteUnAdmin();
        $this->filesystem->rename($this->installes, $this->installes.'-range');

        try {
            $crawler = $this->client->request('GET', '/admin/environnements');
            $this->assertResponseIsSuccessful();
            $this->assertCount(0, $crawler->filter('form[action$="/environnements/installer"]'));
            $this->assertStringContainsString('INSTALLED_ENVIRONMENTS_DIR', $crawler->filter('body')->text());
        } finally {
            $this->filesystem->rename($this->installes.'-range', $this->installes);
        }
    }

    public function testUneAdresseQuiNestPasHttpsEstRefuseeSansRienLancer(): void
    {
        $this->connecteUnAdmin();
        $this->client->request('GET', '/admin/environnements');
        $this->client->submitForm('Installer', ['depot' => 'file:///etc/passwd']);

        $this->client->followRedirect();
        $this->assertStringContainsString('doit commencer par « https:// »', $this->client->getResponse()->getContent() ?: '');
        $this->assertSame([], static::getContainer()->get(InstalledEnvironments::class)->jobs(), 'Rien n\'a été lancé.');
    }

    public function testSansJetonCsrfLInstallationEstRefusee(): void
    {
        $this->connecteUnAdmin();
        $this->client->request('POST', '/admin/environnements/installer', ['depot' => 'https://exemple.test/depot.git']);

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame([], static::getContainer()->get(InstalledEnvironments::class)->jobs());
    }

    public function testSansJetonCsrfLaSynchronisationEstRefusee(): void
    {
        $this->connecteUnAdmin();
        $this->client->request('POST', '/admin/environnements/synchroniser');

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame([], static::getContainer()->get(InstalledEnvironments::class)->jobs());
    }

    /** Les archives passent par un contrôleur : un nom qui n'en est pas un ne descend pas dans le disque. */
    public function testLesArchivesNeServentQueDesNomsDArchives(): void
    {
        $this->client->request('GET', '/envs/symfony-8.completion.json');
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/envs/environnement-absent.zip');
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/envs/..%2F..%2Fetc%2Fpasswd');
        $this->assertResponseStatusCodeSame(404);
    }

    private function connecteUnAdmin(): User
    {
        $admin = $this->createUser('admin@example.test', 'Admin');
        $admin->setRoles([User::ROLE_ADMIN]);
        static::getContainer()->get('doctrine')->getManager()->flush();
        $this->client->loginUser($admin);

        return $admin;
    }
}
