<?php

namespace App\Tests\Controller;

use App\Entity\InstanceSetting;
use App\Entity\User;
use App\Repository\InstanceSettingRepository;
use App\Tests\DatabaseTrait;
use App\Theme\ActiveTheme;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * La page « Thèmes » de l'admin : plusieurs thèmes installés dans THEMES_DIR, un aperçu réservé à l'administrateur,
 * et l'activation pour tous, sans redémarrer ni vider le cache.
 */
final class ThemeAdminTest extends WebTestCase
{
    use DatabaseTrait;

    private KernelBrowser $client;
    private string $tmp;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tmp = sys_get_temp_dir().'/themes-'.bin2hex(random_bytes(6));
        // Un thème valable, repérable à son pied de page ; un thème en erreur ; un dossier qui n'est pas un thème.
        $this->filesystem->dumpFile($this->tmp.'/atelier/theme.yaml', "name: Atelier Bigorneau\n");
        $this->filesystem->dumpFile($this->tmp.'/atelier/templates/_footer.html.twig', '<footer class="site-footer">Pied de page de l\'atelier</footer>');
        $this->filesystem->dumpFile($this->tmp.'/casse/theme.yaml', "name: Cassé\ncolors:\n  accent: rouge\n");
        $this->filesystem->dumpFile($this->tmp.'/casse/templates/home.html.twig', '{{ fonction_inconnue() }}');
        $this->filesystem->dumpFile($this->tmp.'/Mauvais_Nom/theme.yaml', "name: A\n");
        foreach (['_ENV', '_SERVER'] as $store) {
            $GLOBALS[$store]['THEMES_DIR'] = $this->tmp;
        }
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        // Le réglage ne doit pas survivre à ce test : les suivants ne remettent pas tous la base à zéro.
        static::getContainer()->get('doctrine')->getManager()->createQuery('DELETE FROM '.InstanceSetting::class)->execute();
        parent::tearDown();
        $this->filesystem->remove($this->tmp);
        // Remis à la valeur de .env, et non retiré : une variable absente ferait échouer les tests suivants.
        foreach (['_ENV', '_SERVER'] as $store) {
            $GLOBALS[$store]['THEMES_DIR'] = '';
        }
    }

    public function testLaPageEstReserveeAuxAdmins(): void
    {
        $this->client->request('GET', '/admin/themes');
        $this->assertResponseRedirects('/connexion', message: 'Un anonyme est envoyé à la connexion.');

        $auteur = $this->createUser()->setRoles([User::ROLE_AUTEUR]);
        static::getContainer()->get('doctrine')->getManager()->flush();
        $this->client->loginUser($auteur);
        $this->client->request('GET', '/admin/themes');
        $this->assertResponseStatusCodeSame(403, 'Un auteur n\'y a pas accès.');
    }

    public function testLaPageListeLesThemesAvecLeurEtat(): void
    {
        $this->connecteUnAdmin();
        $this->client->request('GET', '/admin/themes');

        $this->assertResponseIsSuccessful();
        $contenu = (string) $this->client->getResponse()->getContent();
        foreach (['default', 'atelier', 'casse'] as $theme) {
            $this->assertStringContainsString('<code>'.$theme.'</code>', $contenu);
        }
        $this->assertStringContainsString('Atelier Bigorneau', $contenu);
        $this->assertStringContainsString('colors.accent', $contenu, 'L\'erreur de theme.yaml est affichée.');
        $this->assertStringContainsString('fonction_inconnue', $contenu, 'Un gabarit qui ne se compile pas aussi.');
        $this->assertSelectorNotExists('form[action$="/themes/casse/activer"]', 'Un thème en erreur ne se propose pas à l\'activation.');
        $this->assertStringContainsString('Mauvais_Nom', $contenu, 'Un dossier ignoré est signalé, avec sa raison.');
    }

    public function testUnThemeEnErreurNeSActivePas(): void
    {
        $this->connecteUnAdmin();
        $this->client->request('POST', '/admin/themes/casse/activer', ['_token' => $this->jeton()]);

        $this->assertResponseRedirects('/admin/themes');
        $this->assertNull($this->reglage(), 'Rien n\'est enregistré.');
    }

    public function testSansJetonCsrfLActivationEstRefusee(): void
    {
        $this->connecteUnAdmin();
        $this->client->request('POST', '/admin/themes/atelier/activer');

        $this->assertResponseStatusCodeSame(403);
        $this->assertNull($this->reglage());
    }

    /** L'aperçu : l'administrateur voit le thème, et le sait ; un visiteur, lui, voit toujours le thème actif. */
    public function testLApercuNEstVuQueParLAdministrateur(): void
    {
        $this->connecteUnAdmin();
        $this->client->request('POST', '/admin/themes/atelier/apercu', ['_token' => $this->jeton()]);
        $this->assertResponseRedirects('/');

        $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.site-footer', 'Pied de page de l\'atelier');
        $this->assertSelectorTextContains('.theme-preview', 'Aperçu du thème « atelier »');
        $this->assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'), 'Aucun cache ne garde une page en aperçu.');
        $this->assertNull($this->reglage(), 'L\'aperçu n\'active rien.');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextNotContains('.site-footer', 'Pied de page de l\'atelier');
    }

    /** L'activation se voit dès la requête suivante, pour tous, sans vider le cache ; l'ETag change avec la page. */
    public function testUnThemeActiveEstServiATousDesLaRequeteSuivante(): void
    {
        $this->client->request('GET', '/');
        $avant = $this->client->getResponse()->headers->get('ETag');

        $admin = $this->connecteUnAdmin();
        $this->client->request('POST', '/admin/themes/atelier/activer', ['_token' => $this->jeton()]);
        $this->assertResponseRedirects('/admin/themes');
        $reglage = $this->reglage();
        $this->assertNotNull($reglage);
        $this->assertSame('atelier', $reglage->getValue());
        $this->assertSame($admin->getEmail(), $reglage->getUpdatedBy(), 'On garde qui a basculé.');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.site-footer', 'Pied de page de l\'atelier');
        $this->assertNotSame($avant, $this->client->getResponse()->headers->get('ETag'), 'Un visiteur n\'obtient pas un 304 de l\'ancien thème.');
    }

    /** Le dossier du thème actif a disparu : le site reste en ligne avec le thème du moteur, et l'admin le dit. */
    public function testUnThemeActifIntrouvableLaissePlaceAuThemeDuMoteur(): void
    {
        $this->connecteUnAdmin();
        $this->client->request('POST', '/admin/themes/atelier/activer', ['_token' => $this->jeton()]);
        $this->filesystem->remove($this->tmp.'/atelier');

        $this->client->request('GET', '/admin/themes');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.alert-danger', '« atelier », n\'est plus installé');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.site-header .site-name', 'default');
    }

    /** Pour les déploiements scriptés : la même activation, en ligne de commande. */
    public function testLaCommandeActiveUnThemeValable(): void
    {
        $application = new Application(static::$kernel);
        $commande = new CommandTester($application->find('app:theme:activer'));

        $this->assertSame(1, $commande->execute(['theme' => 'casse']), 'Un thème en erreur est refusé.');
        $this->assertSame(1, $commande->execute(['theme' => 'inconnu']));
        $this->assertNull($this->reglage());

        $this->assertSame(0, $commande->execute(['theme' => 'atelier']));
        $reglage = $this->reglage();
        $this->assertNotNull($reglage);
        $this->assertSame('atelier', $reglage->getValue());
        $this->assertNull($reglage->getUpdatedBy(), 'Une commande n\'a pas d\'auteur.');
    }

    private function connecteUnAdmin(): User
    {
        $admin = $this->createUser('admin@example.test', 'Admin');
        $admin->setRoles([User::ROLE_ADMIN]);
        static::getContainer()->get('doctrine')->getManager()->flush();
        $this->client->loginUser($admin);

        return $admin;
    }

    private function jeton(): string
    {
        $crawler = $this->client->request('GET', '/admin/themes');

        return (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
    }

    private function reglage(): ?InstanceSetting
    {
        $manager = static::getContainer()->get('doctrine')->getManager();
        $manager->clear();

        return static::getContainer()->get(InstanceSettingRepository::class)->setting(ActiveTheme::SETTING);
    }
}
