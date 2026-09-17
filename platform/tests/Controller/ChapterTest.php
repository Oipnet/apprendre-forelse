<?php

namespace App\Tests\Controller;

use App\Content\ContentRepository;
use App\Entity\User;
use App\Service\ProgressService;
use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** La fiche de cours du chapitre « bonjour » du pack de démo. */
final class ChapterTest extends WebTestCase
{
    use DatabaseTrait;

    private const string PAGE = '/parcours/decouverte/chapitre/bonjour';

    private function completeChapter(User $user, string ...$exerciseIds): void
    {
        $content = static::getContainer()->get(ContentRepository::class);
        $progress = static::getContainer()->get(ProgressService::class);
        // Entre deux requêtes, Doctrine repart d'un gestionnaire vide : on recharge l'utilisateur.
        $user = static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $user->getId()) ?? $user;
        foreach ($exerciseIds as $id) {
            $progress->complete($user, $content->findExercise('decouverte', $id), 0);
        }
    }

    public function testUnInviteEstInviteASeConnecter(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', self::PAGE);

        $this->assertResponseRedirects('/connexion');
    }

    public function testLaFicheResteVerrouilleeTantQueLeChapitreNEstPasReussi(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $this->completeChapter($user, '01-bonjour');
        $client->loginUser($user);

        $client->request('GET', self::PAGE);
        $this->assertResponseRedirects('/parcours/decouverte');
        $crawler = $client->followRedirect();

        $this->assertSelectorTextContains('.flash', 'encore 1 à terminer');
        $this->assertSelectorTextContains('.lesson-link.locked', 'encore 1 exercice à réussir');
        $this->assertSelectorNotExists('a.lesson-link');
    }

    public function testLaFicheSeLitUneFoisLeChapitreReussi(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $this->completeChapter($user, '01-bonjour', '02-bonjour-prenom');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/parcours/decouverte');
        $this->assertResponseIsSuccessful();
        $this->assertSame(self::PAGE, $crawler->filter('a.lesson-link')->attr('href'));

        $crawler = $client->request('GET', self::PAGE);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Bonjour Symfony');
        $this->assertSelectorTextContains('.lesson h2', 'Ce que vous avez appris');
        $this->assertSelectorTextContains('.lesson-summary', '100 XP gagnés');
        $this->assertGreaterThan(0, $crawler->filter('.lesson pre[data-lang="php"] .hl-keyword')->count(), 'Les blocs de code sont colorés.');
        $this->assertSelectorExists('.lesson a[href="https://symfony.com/doc/current/controller.html"]');
    }

    public function testLaFicheSeTelechargeEnPdfUneFoisLeChapitreReussi(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $this->completeChapter($user, '01-bonjour', '02-bonjour-prenom');
        $client->loginUser($user);

        $crawler = $client->request('GET', self::PAGE);
        $this->assertSame(self::PAGE.'/fiche.pdf', $crawler->filter('a[href$=".pdf"]')->attr('href'));

        $client->request('GET', self::PAGE.'/fiche.pdf');
        $response = $client->getResponse();

        $this->assertResponseIsSuccessful();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('inline; filename=decouverte-chapitre-01-bonjour-symfony.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), 'Document personnalisé : pas de cache.');
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        $this->assertGreaterThan(5000, \strlen((string) $response->getContent()));
    }

    public function testLePdfResteVerrouilleCommeLaPage(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $client->loginUser($this->createUser());

        $client->request('GET', self::PAGE.'/fiche.pdf');

        $this->assertResponseRedirects('/parcours/decouverte');
    }

    public function testLeLivretDuParcoursSeTelechargeUneFoisLeParcoursTermine(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $this->completeChapter($user, '01-bonjour');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/parcours/decouverte');
        $this->assertSelectorNotExists('a[href$="livret.pdf"]', 'Pas de livret tant que le parcours n\'est pas terminé…');
        $client->request('GET', '/parcours/decouverte/livret.pdf');
        $this->assertResponseRedirects('/parcours/decouverte');
        $this->assertSelectorTextContains('.flash', 'encore 1 exercice à réussir', $client->followRedirect()->text());

        $this->completeChapter($user, '02-bonjour-prenom');
        $crawler = $client->request('GET', '/parcours/decouverte');
        $this->assertSame('/parcours/decouverte/livret.pdf', $crawler->filter('.track-done a[href$="livret.pdf"]')->attr('href'), '… puis il est proposé avec les félicitations.');

        $client->request('GET', '/parcours/decouverte/livret.pdf');
        $response = $client->getResponse();
        $this->assertResponseIsSuccessful();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('inline; filename=decouverte-livret.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    public function testLeLivretNEstPasConfonduAvecUnExercice(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/parcours/decouverte/livret.pdf');
        $this->assertResponseRedirects('/connexion', null, 'Un invité est envoyé vers la connexion, pas vers un exercice « livret.pdf ».');

        $client->request('GET', '/parcours/decouverte/01-bonjour');
        $this->assertResponseIsSuccessful('La route des exercices fonctionne toujours.');
    }

    public function testUnAuteurLitLaFicheSansAvoirJoue(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser('auteur@example.test', 'Auteur');
        $user->setRoles([User::ROLE_AUTEUR]);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($user);

        $client->request('GET', self::PAGE);

        $this->assertResponseIsSuccessful();
    }

    public function testChapitreInconnuOuSansFiche(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $client->loginUser($this->createUser());

        $client->request('GET', '/parcours/decouverte/chapitre/inexistant');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testLeDernierExerciceDuChapitreAnnonceLaFiche(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $client->loginUser($this->createUser());

        $client->request('GET', '/api/exercises/decouverte/02-bonjour-prenom');
        $this->assertResponseIsSuccessful();
        $this->assertSame(['title' => 'Bonjour Symfony', 'url' => self::PAGE], json_decode($client->getResponse()->getContent(), true)['lesson']);

        $client->request('GET', '/api/exercises/decouverte/01-bonjour');
        $this->assertNull(json_decode($client->getResponse()->getContent(), true)['lesson'], 'Seul le dernier exercice du chapitre débloque la fiche.');
    }
}
