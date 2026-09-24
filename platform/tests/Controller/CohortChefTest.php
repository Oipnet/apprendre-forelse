<?php

namespace App\Tests\Controller;

use App\Content\ContentRepository;
use App\Entity\Cohort;
use App\Entity\FundingMode;
use App\Entity\User;
use App\Repository\CohortRepository;
use App\Repository\ExerciseProgressRepository;
use App\Service\ProgressService;
use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Chefs de cohorte et parcours proposés par cohorte. Pack de test : « Bases de Symfony », « Bases de Laravel »
 * et « Atelier en préparation » (visibility: admin).
 */
final class CohortChefTest extends WebTestCase
{
    use DatabaseTrait;

    private const array ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];

    private KernelBrowser $client;
    private ?string $cheminsInitiaux = null;

    protected function setUp(): void
    {
        $this->cheminsInitiaux = $_SERVER['CONTENT_PACKS_PATHS'] ?? null;
        $_SERVER['CONTENT_PACKS_PATHS'] = $_ENV['CONTENT_PACKS_PATHS'] = __DIR__.'/../Fixtures/packs/cohortes';
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        if (null === $this->cheminsInitiaux) {
            unset($_SERVER['CONTENT_PACKS_PATHS'], $_ENV['CONTENT_PACKS_PATHS']);
        } else {
            $_SERVER['CONTENT_PACKS_PATHS'] = $_ENV['CONTENT_PACKS_PATHS'] = $this->cheminsInitiaux;
        }
        parent::tearDown();
    }

    public function testUnChefVoitSesCohortesEtPasCellesDesAutres(): void
    {
        $chef = $this->chef('prof@example.test', 'Prof');
        $autreChef = $this->chef('collegue@example.test', 'Collègue');
        $mienne = $this->cohorte('iut-annecy', [$chef]);
        $autre = $this->cohorte('iut-lyon', [$autreChef]);
        $this->createUser('ada@example.test', 'Ada', 'iut-annecy');
        $this->createUser('bob@example.test', 'Bob', 'iut-lyon');

        $this->client->loginUser($chef);
        $crawler = $this->client->request('GET', '/cohorte');
        $this->assertResponseIsSuccessful();
        $liste = $crawler->filter('#main')->text();
        $this->assertStringContainsString('iut-annecy', $liste);
        $this->assertStringNotContainsString('iut-lyon', $liste, 'La cohorte d\'un autre chef n\'est pas listée.');
        $this->assertSame('http://localhost/inscription?code=iut-annecy', $crawler->filter('.beta-copy input')->attr('value'), 'Lien d\'invitation copiable.');
        $this->assertSame('1', trim($crawler->filter('tbody td')->eq(3)->text()), 'Nombre d\'apprenants.');
        $this->assertSame('Tous', trim($crawler->filter('tbody td')->last()->text()), 'Sans sélection, la cohorte propose tous les parcours.');

        $this->client->request('GET', '/cohorte/'.$mienne->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#main', 'Premier chapitre Symfony', 'L\'avancement par chapitre est affiché.');

        $this->client->request('GET', '/cohorte/'.$autre->getId());
        $this->assertResponseStatusCodeSame(403, 'La page d\'une cohorte d\'un autre chef est refusée.');
        $this->client->request('POST', '/cohorte/'.$autre->getId().'/parcours', ['cohort_tracks' => ['trackIds' => ['laravel-bases']]], server: self::ORIGIN);
        $this->assertResponseStatusCodeSame(403, 'Ses parcours ne se modifient pas.');
        $this->assertFalse($this->recharger($autre)->hasTrackSelection());

        $this->client->request('GET', '/cohorte/999999');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testLEspaceDesChefsEstCloisonne(): void
    {
        $this->client->request('GET', '/cohorte');
        $this->assertResponseRedirects('/connexion', message: 'Un anonyme est envoyé à la connexion.');

        $apprenant = $this->createUser('ada@example.test', 'Ada', 'iut-annecy');
        $this->client->loginUser($apprenant);
        $this->client->request('GET', '/cohorte');
        $this->assertResponseStatusCodeSame(403, 'Un apprenant n\'a pas accès à l\'espace des chefs.');
        $this->client->request('GET', '/cohorte/'.$apprenant->getCohort()->getId());
        $this->assertResponseStatusCodeSame(403, 'Ni à la page de sa propre cohorte.');

        $chef = $this->chef();
        $this->client->loginUser($chef);
        foreach (['/admin', '/admin/apprenants', '/admin/retours', '/admin/liste-d-attente', '/admin/cohortes/new'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseStatusCodeSame(403, sprintf('%s reste réservé à l\'admin.', $url));
        }
        // EasyAdmin crée les routes CRUD sous chaque tableau de bord : aucune ne doit exister sous /cohorte.
        foreach (['/cohorte/apprenants', '/cohorte/cohortes', '/cohorte/cohortes/new', '/cohorte/retours', '/cohorte/cohorte/iut-annecy'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseStatusCodeSame(404, sprintf('%s n\'existe pas dans l\'espace des chefs.', $url));
        }

        $crawler = $this->client->request('GET', '/cohorte');
        $this->assertCount(0, $crawler->selectLink('Créer une cohorte'));
        $this->assertCount(0, $crawler->selectLink('Administration'));

        $this->client->request('GET', '/');
        $this->assertSelectorExists('header a[href="/cohorte"]', 'Entrée « Mes cohortes » pour un chef.');
        $this->client->loginUser($apprenant);
        $this->client->request('GET', '/');
        $this->assertSelectorNotExists('header a[href="/cohorte"]', 'Pas pour un apprenant.');
    }

    public function testUnChefChoisitLesParcoursDeSaCohorte(): void
    {
        $chef = $this->chef();
        $cohorte = $this->cohorte('iut-annecy', [$chef]);
        $this->client->loginUser($chef);

        $crawler = $this->client->request('GET', '/cohorte/'.$cohorte->getId());
        $this->assertCount(3, $crawler->filter('input[name="cohort_tracks[trackIds][]"]'), 'Une case par parcours installé.');
        $this->assertSame('disabled', $crawler->filter('input[value="atelier-secret"]')->attr('disabled'), 'Un chef ne coche pas un parcours en préparation.');

        $form = $crawler->selectButton('Enregistrer les parcours')->form();
        $this->client->request('POST', $form->getUri(), ['cohort_tracks' => [
            // Case désactivée forcée à la main : ignorée.
            'trackIds' => ['laravel-bases', 'atelier-secret'],
            '_token' => $form->get('cohort_tracks[_token]')->getValue(),
        ]], server: self::ORIGIN);
        $this->assertResponseRedirects('/cohorte/'.$cohorte->getId());
        $crawler = $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'Parcours de « iut-annecy » enregistrés : Bases de Laravel.');
        $this->assertSame(['laravel-bases'], $this->recharger($cohorte)->getAvailableTrackIds());
        $this->assertSame('checked', $crawler->filter('input[value="laravel-bases"]')->attr('checked'));

        $this->assertStringNotContainsString('Premier chapitre Symfony', $crawler->filter('#main')->text(), 'L\'avancement ne montre que les parcours proposés.');
        $this->assertStringContainsString('Premier chapitre Laravel', $crawler->filter('#main')->text());

        // Tout décocher : refusé pour une cohorte financée par l'établissement (elle ouvrirait tout, sans devis)…
        $form = $crawler->selectButton('Enregistrer les parcours')->form();
        $this->client->request('POST', $form->getUri(), ['cohort_tracks' => ['_token' => $form->get('cohort_tracks[_token]')->getValue()]], server: self::ORIGIN);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-danger', 'au moins un parcours');
        $this->assertSame(['laravel-bases'], $this->recharger($cohorte)->getAvailableTrackIds());

        // … accepté quand les apprenants paient eux-mêmes : retour à « tous les parcours ».
        $this->recharger($cohorte)->setFundingMode(FundingMode::Learners);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->request('POST', $form->getUri(), ['cohort_tracks' => ['_token' => $form->get('cohort_tracks[_token]')->getValue()]], server: self::ORIGIN);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'propose tous les parcours');
        $this->assertFalse($this->recharger($cohorte)->hasTrackSelection());
    }

    public function testUnApprenantDUneCohorteLimiteeALaravelNeVoitPasSymfony(): void
    {
        $this->cohorte('iut-annecy', tracks: ['laravel-bases']);
        $this->client->loginUser($this->createUser('ada@example.test', 'Ada', 'iut-annecy'));

        $this->client->request('GET', '/');
        $accueil = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('/parcours/laravel-bases', $accueil);
        $this->assertStringNotContainsString('/parcours/symfony-bases', $accueil, 'Symfony n\'est pas au catalogue de la cohorte.');
        foreach (['/parcours/symfony-bases', '/parcours/symfony-bases/e1', '/api/exercises/symfony-bases/e1'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseStatusCodeSame(404, sprintf('%s est introuvable pour elle.', $url));
        }
        $this->client->request('GET', '/parcours/laravel-bases');
        $this->assertResponseIsSuccessful();

        // Sans cohorte, ou dans une cohorte sans sélection : rien ne change (sauf le parcours en préparation, toujours caché).
        $this->cohorte('libre');
        foreach ([$this->createUser('bob@example.test', 'Bob'), $this->createUser('cleo@example.test', 'Cléo', 'libre')] as $apprenant) {
            $this->client->loginUser($apprenant);
            $this->client->request('GET', '/');
            $accueil = (string) $this->client->getResponse()->getContent();
            $this->assertStringContainsString('/parcours/symfony-bases', $accueil);
            $this->assertStringContainsString('/parcours/laravel-bases', $accueil);
            $this->assertStringNotContainsString('/parcours/atelier-secret', $accueil);
        }
    }

    public function testRetirerUnParcoursConserveLaProgression(): void
    {
        $chef = $this->chef();
        $cohorte = $this->cohorte('iut-annecy', [$chef]);
        $ada = $this->createUser('ada@example.test', 'Ada', 'iut-annecy');
        $this->createUser('bob@example.test', 'Bob', 'iut-annecy');
        $exercice = static::getContainer()->get(ContentRepository::class)->findExercise('symfony-bases', 'e1');
        static::getContainer()->get(ProgressService::class)->saveDraft($ada, $exercice, ['src/Controller/BonjourController.php' => '<?php // brouillon'], 1);

        $this->client->loginUser($chef);
        $crawler = $this->client->request('GET', '/cohorte/'.$cohorte->getId());
        $this->assertSame('1 apprenant en cours', trim($crawler->filter('input[value="symfony-bases"]')->closest('tr')->filter('.badge-warning')->text()), 'Le parcours commencé est signalé avant son retrait.');
        $this->assertCount(0, $crawler->filter('input[value="laravel-bases"]')->closest('tr')->filter('.badge-warning'));

        $form = $crawler->selectButton('Enregistrer les parcours')->form();
        $this->client->request('POST', $form->getUri(), ['cohort_tracks' => ['trackIds' => ['laravel-bases'], '_token' => $form->get('cohort_tracks[_token]')->getValue()]], server: self::ORIGIN);
        $this->assertResponseRedirects();

        $progression = static::getContainer()->get(ExerciseProgressRepository::class)->findByTrack($ada, 'symfony-bases');
        $this->assertArrayHasKey('e1', $progression, 'Le retrait ne supprime pas la progression.');
        $this->assertSame(['src/Controller/BonjourController.php' => '<?php // brouillon'], $progression['e1']->getFiles());

        $this->client->loginUser($ada);
        $this->client->request('GET', '/');
        $this->assertStringContainsString('/parcours/symfony-bases', (string) $this->client->getResponse()->getContent(), 'Ada, qui l\'a commencé, le voit encore.');
        $this->client->request('GET', '/parcours/symfony-bases/e1');
        $this->assertResponseIsSuccessful('Et peut le terminer.');

        $this->client->loginUser($this->userByEmail('bob@example.test'));
        $this->client->request('GET', '/parcours/symfony-bases');
        $this->assertResponseStatusCodeSame(404, 'Bob, qui ne l\'a pas commencé, ne le voit plus.');
    }

    public function testLAdminOuvreUnParcoursEnPreparationAUneCohorte(): void
    {
        $admin = $this->createUser('admin@example.test', 'Admin');
        $admin->setRoles([User::ROLE_ADMIN]);
        $this->cohorte('autre');
        $cohorte = $this->cohorte('iut-annecy');
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/cohorte');
        $this->assertResponseIsSuccessful('L\'admin a accès à l\'espace des chefs…');
        $this->assertCount(2, $crawler->filter('tbody tr'), '… et y voit toutes les cohortes.');

        $crawler = $this->client->request('GET', '/cohorte/'.$cohorte->getId());
        $this->assertNull($crawler->filter('input[value="atelier-secret"]')->attr('disabled'), 'L\'admin peut cocher un parcours en préparation.');
        $form = $crawler->selectButton('Enregistrer les parcours')->form();
        $this->client->request('POST', $form->getUri(), ['cohort_tracks' => ['trackIds' => ['symfony-bases', 'atelier-secret'], '_token' => $form->get('cohort_tracks[_token]')->getValue()]], server: self::ORIGIN);
        $this->assertSame(['symfony-bases', 'atelier-secret'], $this->recharger($cohorte)->getAvailableTrackIds());

        $this->client->loginUser($this->createUser('ada@example.test', 'Ada', 'iut-annecy'));
        $this->client->request('GET', '/parcours/atelier-secret');
        $this->assertResponseIsSuccessful('La cohorte ouvre le parcours en préparation à ses apprenants.');
        $this->client->loginUser($this->createUser('bob@example.test', 'Bob', 'autre'));
        $this->client->request('GET', '/parcours/atelier-secret');
        $this->assertResponseStatusCodeSame(404, 'Pas aux autres.');

        // Un chef qui enregistre ensuite ses parcours ne referme pas ce que l'admin a ouvert.
        $cohorte = $this->recharger($cohorte);
        $chef = $this->chef();
        $cohorte->addChef($chef);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($chef);
        $crawler = $this->client->request('GET', '/cohorte/'.$cohorte->getId());
        $form = $crawler->selectButton('Enregistrer les parcours')->form();
        $this->client->request('POST', $form->getUri(), ['cohort_tracks' => ['trackIds' => ['laravel-bases'], '_token' => $form->get('cohort_tracks[_token]')->getValue()]], server: self::ORIGIN);
        $this->assertSame(['laravel-bases', 'atelier-secret'], $this->recharger($cohorte)->getAvailableTrackIds());
    }

    public function testLAdminChoisitParcoursEtChefsDansLeFormulaireDeCohorte(): void
    {
        $admin = $this->createUser('admin@example.test', 'Admin');
        $admin->setRoles([User::ROLE_ADMIN]);
        $chef = $this->chef();
        $this->createUser('ada@example.test', 'Ada');
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/cohortes/new');
        $this->assertResponseIsSuccessful();
        $this->assertCount(3, $crawler->filter('input[name="Cohort[availableTrackIds][]"]'), 'Tous les parcours, y compris en préparation.');
        $this->assertStringContainsString('Atelier en préparation (en préparation)', $crawler->filter('#main')->text());
        $this->assertSame(['Prof'], $crawler->filter('select[name="Cohort[chefs][]"] option')->extract(['_text']), 'Seuls les comptes ayant le rôle sont proposés comme chefs.');

        $form = $crawler->selectButton('Créer')->form();
        $values = $form->getPhpValues();
        $values['Cohort']['name'] = 'BUT Info Annecy';
        $values['Cohort']['code'] = 'iut-annecy';
        $values['Cohort']['availableTrackIds'] = ['laravel-bases'];
        $values['Cohort']['chefs'] = [(string) $chef->getId()];
        $this->client->request('POST', $form->getUri(), $values, server: self::ORIGIN);
        $this->assertResponseRedirects();

        // Le code a reçu sa partie aléatoire à la création.
        $cohorte = static::getContainer()->get(CohortRepository::class)->findOneBy(['name' => 'BUT Info Annecy']);
        $this->assertStringStartsWith('iut-annecy-', (string) $cohorte->getCode());
        $this->assertSame(['laravel-bases'], $cohorte->getAvailableTrackIds());
        $this->assertTrue($cohorte->isChef($chef));

        $this->createUser('bob@example.test', 'Bob', (string) $cohorte->getCode());
        $crawler = $this->client->request('GET', '/admin');
        $this->assertSame('BUT Info Annecy', $crawler->filter('.beta-panel h2')->first()->text());
        $this->assertSame(['Bases de Laravel'], $crawler->filter('.beta-panel')->first()->filter('h3')->extract(['_text']), '« Avancement par cohorte » n\'affiche que les parcours proposés.');
        $this->assertCount(3, $crawler->filter('.beta-panel')->last()->filter('h3'), 'Les comptes sans cohorte gardent tous les parcours.');
        $crawler = $this->client->request('GET', '/admin/cohortes');
        $this->assertStringContainsString('Bases de Laravel', $crawler->filter('tbody')->text());
        $this->assertStringContainsString('Prof', $crawler->filter('tbody')->text());
    }

    private function chef(string $email = 'prof@example.test', string $nom = 'Prof'): User
    {
        $chef = $this->createUser($email, $nom);
        $chef->setRoles([User::ROLE_CHEF_COHORTE]);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $chef;
    }

    /**
     * @param list<User>   $chefs
     * @param list<string> $tracks
     */
    private function cohorte(string $code, array $chefs = [], array $tracks = []): Cohort
    {
        $cohorte = $this->createCohort($code)->setAvailableTrackIds($tracks);
        foreach ($chefs as $chef) {
            $cohorte->addChef($chef);
        }
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $cohorte;
    }

    private function recharger(Cohort $cohort): Cohort
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(Cohort::class, $cohort->getId());
    }

    private function userByEmail(string $email): User
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['email' => $email]);
    }
}
