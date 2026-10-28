<?php

namespace App\Tests\Controller;

use App\Cohort\FreeClasses;
use App\Content\ContentRepository;
use App\Entity\Cohort;
use App\Entity\User;
use App\Service\ProgressService;
use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les classes gratuites : un formateur crée sa classe sur des exercices de la Pratique et suit ses élèves. Pack de test
 * « pratique » (nouveaute-recente, point-precis, cote-laravel ; en-preparation et programme cachés), à côté du pack de démo.
 */
final class FreeClassTest extends WebTestCase
{
    use DatabaseTrait;

    private const array ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];
    private const string URL = '/ecoles-et-entreprises/classe-gratuite';

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

    public function testLaPageDesEcolesProposeLaClasseGratuite(): void
    {
        $this->client->request('GET', '/ecoles-et-entreprises');

        $this->assertSelectorExists('#classe-gratuite a[href="'.self::URL.'"]');
        $this->client->request('GET', self::URL);
        $this->assertResponseRedirects('/connexion', message: 'Un visiteur se connecte ou s\'inscrit d\'abord.');
    }

    public function testUnFormateurCreeSaClasseEtArriveDansSonSuivi(): void
    {
        $prof = $this->createUser('prof@example.test', 'Prof');
        $this->client->loginUser($prof);

        $crawler = $this->client->request('GET', self::URL);
        $this->assertResponseIsSuccessful();
        $choix = $crawler->filter('input[name="free_class[practiceIds][]"]')->extract(['value']);
        $this->assertContains('point-precis', $choix);
        $this->assertContains('cote-laravel', $choix);
        $this->assertNotContains('en-preparation', $choix, 'Un exercice en préparation ne se choisit pas.');
        $this->assertNotContains('programme', $choix, 'Ni un exercice programmé.');

        $this->creer($crawler, 'BTS SIO 2 — Bordeaux', ['point-precis', 'cote-laravel']);

        $cohorte = $this->classeDe('prof@example.test');
        $this->assertResponseRedirects('/cohorte/'.$cohorte->getId());
        $this->assertTrue($cohorte->isFreeClass());
        $this->assertEqualsCanonicalizing(['point-precis', 'cote-laravel'], $cohorte->getPracticeIds());
        $this->assertStringStartsWith('bts-sio-2-bordeaux-', $cohorte->getCode(), 'Code lisible, plus une partie aléatoire.');
        $this->assertFalse($cohorte->isFundedByInstitution(), 'Aucun parcours payé par la classe.');
        $this->assertFalse($cohorte->hasTrackSelection());
        $this->assertTrue($cohorte->isChef($this->utilisateur('prof@example.test')));
        $this->assertContains(User::ROLE_CHEF_COHORTE, $this->utilisateur('prof@example.test')->getRoles());

        $crawler = $this->client->followRedirect();
        $this->assertResponseIsSuccessful('Le rôle de chef vaut dès la redirection, sans se reconnecter.');
        $this->assertSame('http://localhost/inscription?code='.$cohorte->getCode(), $crawler->filter('.beta-copy input')->attr('value'));
        $this->assertSelectorTextContains('#main', 'Personne ne s\'est encore inscrit');
        $this->assertSelectorNotExists('#financement', 'Ni financement, ni parcours, ni soutenances pour une classe gratuite.');
        $this->assertSelectorNotExists('#soutenances');
    }

    public function testLeFormateurSuitSesElevesQuiRetrouventLeursExercices(): void
    {
        $prof = $this->createUser('prof@example.test', 'Prof');
        $cohorte = static::getContainer()->get(FreeClasses::class)->create($prof, 'BTS SIO', ['nouveaute-recente', 'point-precis']);
        $ada = $this->createUser('ada@example.test', 'Ada', $cohorte->getCode());
        $this->createUser('bob@example.test', 'Bob', $cohorte->getCode());
        $content = static::getContainer()->get(ContentRepository::class);
        $progress = static::getContainer()->get(ProgressService::class);
        $progress->complete($ada, $content->findPractice('point-precis')->exercise, 0);
        $progress->saveDraft($ada, $content->findPractice('nouveaute-recente')->exercise, [], 0);

        $this->client->loginUser($this->utilisateur('prof@example.test'));
        $crawler = $this->client->request('GET', '/cohorte/'.$cohorte->getId());
        $this->assertResponseIsSuccessful();
        $lignes = $crawler->filter('.free-class-progress tbody tr');
        $this->assertCount(2, $lignes);
        $this->assertSame(['Ada', 'En cours', 'Réussi', '1 / 2'], $lignes->eq(0)->filter('td')->each(static fn ($td) => trim($td->text())));
        $this->assertSame('0 / 2', trim($lignes->eq(1)->filter('td')->last()->text()));

        $crawler = $this->client->request('GET', '/cohorte');
        $this->assertStringContainsString('Classe gratuite · 2 exercices', $crawler->filter('#main')->text());

        $this->client->loginUser($this->utilisateur('ada@example.test'));
        $crawler = $this->client->request('GET', '/pratique');
        $this->assertResponseIsSuccessful();
        $this->assertSame('Votre classe : BTS SIO', trim($crawler->filter('.pr-class h2')->text()));
        $this->assertSame(['Une nouveauté récente', 'Un point précis'], $crawler->filter('.pr-class .title')->extract(['_text']), 'Dans l\'ordre choisi par le formateur.');
        $this->assertSame(['in_progress', 'completed'], $crawler->filter('.pr-class li')->extract(['data-state']));
        $this->assertStringContainsString('1 réussi sur 2', $crawler->filter('.pr-class')->text());

        $this->client->loginUser($this->createUser('libre@example.test', 'Libre'));
        $this->client->request('GET', '/pratique');
        $this->assertSelectorNotExists('.pr-class', 'Sans classe, rien en tête de la Pratique.');
    }

    public function testLeFormateurChangeLesExercicesMaisPasLesParcours(): void
    {
        $prof = $this->createUser('prof@example.test', 'Prof');
        $cohorte = static::getContainer()->get(FreeClasses::class)->create($prof, 'BTS SIO', ['point-precis']);
        $this->client->loginUser($this->utilisateur('prof@example.test'));
        $crawler = $this->client->request('GET', '/cohorte/'.$cohorte->getId());
        $form = $crawler->filter('#exercices form')->form();
        $token = $form->get('free_class[_token]')->getValue();

        $this->client->request('POST', $form->getUri(), ['free_class' => ['practiceIds' => ['cote-laravel', 'nouveaute-recente'], '_token' => $token]], server: self::ORIGIN);
        $this->assertResponseRedirects('/cohorte/'.$cohorte->getId().'#exercices');
        $this->assertSame(['cote-laravel', 'nouveaute-recente'], $this->recharger($cohorte)->getPracticeIds());

        $this->client->request('POST', $form->getUri(), ['free_class' => ['_token' => $token]], server: self::ORIGIN);
        $this->assertSame(['cote-laravel', 'nouveaute-recente'], $this->recharger($cohorte)->getPracticeIds(), 'Une classe garde au moins un exercice.');

        $this->client->request('POST', '/cohorte/'.$cohorte->getId().'/parcours', ['cohort_tracks' => ['trackIds' => ['decouverte']]], server: self::ORIGIN);
        $this->assertResponseStatusCodeSame(403, 'Une classe gratuite ne choisit pas de parcours.');
        $this->assertFalse($this->recharger($cohorte)->hasTrackSelection());
    }

    public function testUnFormateurOuvreAuPlusTroisClasses(): void
    {
        $prof = $this->createUser('prof@example.test', 'Prof');
        $classes = static::getContainer()->get(FreeClasses::class);
        foreach (['A', 'B', 'C'] as $nom) {
            $classes->create($prof, $nom, ['point-precis']);
        }

        $this->client->loginUser($this->utilisateur('prof@example.test'));
        $crawler = $this->client->request('GET', self::URL);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('form[name="free_class"]');
        $this->assertSelectorTextContains('main', 'Vous avez déjà ouvert vos classes gratuites');
        $this->assertCount(3, $crawler->filter('main a[href^="/cohorte/"]'));
    }

    /** @param list<string> $practiceIds */
    private function creer(\Symfony\Component\DomCrawler\Crawler $crawler, string $nom, array $practiceIds): void
    {
        $form = $crawler->filter('form[name="free_class"]')->form();
        $this->client->request('POST', $form->getUri(), ['free_class' => [
            'name' => $nom,
            'practiceIds' => $practiceIds,
            '_token' => $form->get('free_class[_token]')->getValue(),
        ]], server: self::ORIGIN);
    }

    private function classeDe(string $email): Cohort
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        return static::getContainer()->get(FreeClasses::class)->of($this->utilisateur($email))[0];
    }

    private function utilisateur(string $email): User
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function recharger(Cohort $cohort): Cohort
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(Cohort::class, $cohort->getId());
    }
}
