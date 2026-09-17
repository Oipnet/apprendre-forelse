<?php

namespace App\Tests\Controller;

use App\Content\ContentRepository;
use App\Entity\User;
use App\Repository\ExerciseProgressRepository;
use App\Repository\FeedbackRepository;
use App\Service\ProgressService;
use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La Pratique : exercices hors parcours. Pack de test « pratique » (une nouveauté Symfony 8.1, un point précis
 * sans version, un exercice en préparation, un exercice Laravel), à côté du pack de démo.
 */
final class PracticeTest extends WebTestCase
{
    use DatabaseTrait;

    private KernelBrowser $client;
    private ?string $cheminsInitiaux = null;

    protected function setUp(): void
    {
        $this->cheminsInitiaux = $_SERVER['CONTENT_PACKS_PATHS'] ?? null;
        $_SERVER['CONTENT_PACKS_PATHS'] = $_ENV['CONTENT_PACKS_PATHS'] = __DIR__.'/../../../examples/packs,'.__DIR__.'/../Fixtures/packs/pratique';
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

    public function testLaListeEstPubliqueSansLesExercicesEnPreparation(): void
    {
        $crawler = $this->client->request('GET', '/pratique');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['Lire un en-tête avec #[MapRequestHeader]', 'Une nouveauté récente', 'Un point précis', 'Côté Laravel'], $crawler->filter('.practice-list .title')->extract(['_text']), 'Du plus récent au plus ancien, pack de démo compris.');
        $this->assertStringContainsString('Symfony 8.1', $crawler->filter('.practice-list li')->eq(1)->text());
        $this->assertSelectorExists('header nav a[href="/pratique"]', 'La Pratique est dans le menu.');
    }

    public function testUnAdministrateurVoitLesExercicesEnPreparation(): void
    {
        $admin = $this->createUser('admin@example.test', 'Admin');
        $admin->setRoles([User::ROLE_ADMIN]);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/pratique');
        $this->assertContains('Pas encore publié', $crawler->filter('.practice-list .title')->extract(['_text']));
        $this->assertContains('Publié plus tard', $crawler->filter('.practice-list .title')->extract(['_text']));
        $this->assertStringContainsString('Programmé le 01/01/2099', $crawler->filter('.practice-list li')->first()->text());
        $this->client->request('GET', '/pratique/en-preparation');
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/pratique/programme');
        $this->assertResponseIsSuccessful();
    }

    public function testFiltres(): void
    {
        $titres = fn (string $query) => $this->client->request('GET', '/pratique?'.$query)->filter('.practice-list .title')->extract(['_text']);

        $this->assertSame(['Côté Laravel'], $titres('framework=laravel'));
        $this->assertSame(['Un point précis'], $titres('notions[]=Validator'));
        $this->assertSame(['Lire un en-tête avec #[MapRequestHeader]', 'Une nouveauté récente', 'Côté Laravel'], $titres('nouveautes=1'));
        $this->assertSame(['Un point précis'], $titres('framework=symfony&notions[]=Validator'));
        $this->assertSame(['Côté Laravel', 'Un point précis'], $titres('tri=titre&notions[]=Validator&notions[]=Eloquent'), 'Plusieurs notions se cumulent, et le tri par titre passe avant la date.');
        $this->assertSame(['Une nouveauté récente'], $titres('recherche='.rawurlencode('dernière version')), 'La recherche lit aussi le résumé.');
        $this->assertSame(['Un point précis'], $titres('recherche=validator'), 'La recherche lit aussi les notions, sans tenir compte de la casse.');
        $this->assertCount(4, $titres('notions[]=Inconnue'), 'Une notion que le framework choisi ne connaît pas est oubliée, pas une erreur.');
        $this->assertSame([], $titres('framework=cobol'));
        $this->assertSelectorTextContains('main', 'Aucun exercice avec ces filtres');
    }

    public function testLesExercicesSontRegroupesParDate(): void
    {
        $admin = $this->createUser('admin@example.test', 'Admin');
        $admin->setRoles([User::ROLE_ADMIN]);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($admin);

        $groupes = fn (string $query) => $this->client->request('GET', '/pratique?'.$query)->filter('.pr-set-label')->extract(['_text']);

        $this->assertSame(['À venir'], $groupes('recherche='.rawurlencode('jour à venir')), 'Un exercice programmé se détache du reste.');
        $this->assertSame(['Avant'], $groupes('framework=laravel'), 'Un exercice d\'août n\'est pas de cette semaine.');
        $this->assertSame(['Par titre'], $groupes('tri=titre'), 'Trié par titre, la date ne regroupe plus rien.');
    }

    public function testLesNotionsAu_delaDeHuitSontRepliees(): void
    {
        $crawler = $this->client->request('GET', '/pratique');

        $this->assertCount(8, $crawler->filter('.pr-group > .pr-chips .pr-chip'), 'Huit notions de tête, le reste derrière un dépliant.');
        $this->assertSelectorTextContains('.pr-more summary', 'Voir les 4 autres');
        $this->assertNull($crawler->filter('.pr-more')->attr('open'), 'Replié tant qu\'aucune notion cachée n\'est cochée.');

        $crawler = $this->client->request('GET', '/pratique?notions[]=Validator');
        $this->assertNotNull($crawler->filter('.pr-more')->attr('open'), 'Déplié quand une notion cachée est cochée, sinon on ne la verrait pas.');
        $this->assertNotNull($crawler->filter('.pr-more input[value="Validator"]')->attr('checked'));
    }

    public function testUnVisiteurLitLExerciceEtEstInviteACreerUnCompte(): void
    {
        $this->client->request('GET', '/pratique/point-precis');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorCount(1, 'h1');
        $this->assertSelectorExists('.exercise-instructions');
        $this->assertSelectorNotExists('[data-playground]');
        $this->assertSelectorExists('.exercise-access a[href="/inscription?suite=/pratique/point-precis"]');
        $this->json($this->client, 'GET', '/api/exercises/pratique/point-precis');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testUnExerciceEnPreparationOuInconnuEstIntrouvable(): void
    {
        $this->client->loginUser($this->createUser());

        foreach (['/pratique/en-preparation', '/pratique/programme', '/pratique/inconnu', '/api/exercises/pratique/en-preparation', '/api/exercises/pratique/programme', '/api/progress/pratique/en-preparation'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseStatusCodeSame(404, $url);
        }
    }

    public function testLePlaygroundSaitQuIlEstEnPratique(): void
    {
        $this->client->loginUser($this->createUser());
        $crawler = $this->client->request('GET', '/pratique/nouveaute-recente');

        $this->assertResponseIsSuccessful();
        $config = json_decode($crawler->filter('[data-playground]')->attr('data-config'), true);
        $this->assertSame('practice', $config['context']);
        $this->assertSame(['title' => 'Pratique', 'url' => '/pratique'], $config['back']);
        $this->assertSame('/api/exercises/pratique/nouveaute-recente', $config['exerciseUrl']);
        $this->assertSame(['mode' => 'api', 'url' => '/api/progress/pratique/nouveaute-recente'], $config['progress']);
        $this->assertSame('/api/feedback/pratique/nouveaute-recente', $config['feedbackUrl']);

        $exercise = $this->json($this->client, 'GET', $config['exerciseUrl']);
        $this->assertResponseIsSuccessful();
        $this->assertNull($exercise['trackId']);
        $this->assertSame(0, $exercise['xp']);
        $this->assertNull($exercise['next']);
        $this->assertNull($exercise['nextTrack']);
        $this->assertNull($exercise['lesson']);
        $this->assertSame(['framework' => 'symfony', 'version' => '8.1', 'pullRequest' => 'https://github.com/symfony/symfony/pull/1', 'published' => '2026-09-10'], $exercise['practice']);
    }

    public function testLeParcoursGardeSaConfiguration(): void
    {
        $crawler = $this->client->request('GET', '/parcours/decouverte/01-bonjour');

        $config = json_decode($crawler->filter('[data-playground]')->attr('data-config'), true);
        $this->assertSame('track', $config['context']);
        $this->assertSame('/parcours/decouverte', $config['back']['url']);
        $this->assertNull($this->json($this->client, 'GET', '/api/exercises/decouverte/01-bonjour')['practice']);
    }

    public function testProgressionSansParcoursEtSansXp(): void
    {
        $user = $this->createUser();
        $this->client->loginUser($user);
        $url = '/api/progress/pratique/point-precis';

        $this->json($this->client, 'GET', $url);
        $this->assertResponseStatusCodeSame(204);
        $this->json($this->client, 'PUT', $url, ['files' => ['src/Controller/BonjourController.php' => '<?php // brouillon'], 'hintsUsed' => 0]);
        $this->assertResponseStatusCodeSame(204);
        $this->json($this->client, 'PUT', $url, ['files' => ['src/Controller/BonjourController.php' => '<?php // encore'], 'hintsUsed' => 0]);
        $result = $this->json($this->client, 'POST', $url.'/complete', ['hintsUsed' => 0]);

        $this->assertSame(0, $result['xpEarned']);
        $this->assertSame(['src/Controller/BonjourController.php' => '<?php // encore'], $this->json($this->client, 'GET', $url)['files']);
        $repository = static::getContainer()->get(ExerciseProgressRepository::class);
        $this->assertCount(1, $repository->findAll(), 'Une seule progression par exercice de Pratique.');
        $this->assertNull($repository->findAll()[0]->getTrackId());
        $this->assertArrayHasKey('point-precis', $repository->findPractice($user));
        $this->assertSame([], $repository->findStartedTrackIds($user), 'La Pratique n\'ouvre aucun parcours.');

        $crawler = $this->client->request('GET', '/pratique');
        $this->assertSame('completed', $crawler->filter('.practice-list a[href="/pratique/point-precis"]')->closest('li')?->attr('data-state'));
        $this->assertSame('todo', $crawler->filter('.practice-list a[href="/pratique/nouveaute-recente"]')->closest('li')?->attr('data-state'));
    }

    public function testMemeIdentifiantDansUnParcoursEtEnPratique(): void
    {
        $user = $this->createUser();
        $container = static::getContainer();
        $content = $container->get(ContentRepository::class);
        $progress = $container->get(ProgressService::class);

        $progress->saveDraft($user, $content->findExercise('decouverte', '01-bonjour'), [], 0);
        $progress->saveDraft($user, $content->findPractice('point-precis')->exercise, [], 0);

        $this->assertCount(2, $container->get(ExerciseProgressRepository::class)->findAll());
    }

    public function testRetourSurUnExerciceDePratique(): void
    {
        $this->client->loginUser($this->createUser());
        $this->json($this->client, 'POST', '/api/feedback/pratique/point-precis', ['kind' => 'unclear', 'message' => 'Le résumé ne dit pas tout.']);

        $this->assertResponseStatusCodeSame(201);
        $feedback = static::getContainer()->get(FeedbackRepository::class)->findAll();
        $this->assertCount(1, $feedback);
        $this->assertNull($feedback[0]->getTrackId());
        $this->assertSame('point-precis', $feedback[0]->getExerciseId());
    }

    public function testLeMentorResteReserveAuxComptes(): void
    {
        $this->json($this->client, 'POST', '/api/mentor/pratique/point-precis/review', ['files' => []]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testLesTableauxDeBordIgnorentLaPratique(): void
    {
        $container = static::getContainer();
        $ada = $this->createUser('ada@example.test', 'Ada', 'iut-2026');
        $container->get(ProgressService::class)->complete($ada, $container->get(ContentRepository::class)->findPractice('point-precis')->exercise, 0);
        $admin = $this->createUser('admin@example.test', 'Admin');
        $admin->setRoles([User::ROLE_ADMIN]);
        $container->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin');
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/admin/cohorte/iut-2026');
        $this->assertResponseIsSuccessful();
        $crawler = $this->client->request('GET', '/admin/progression');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Pratique', $crawler->filter('tbody')->text());
    }
}
