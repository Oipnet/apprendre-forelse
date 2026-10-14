<?php

namespace App\Tests\Controller;

use App\Entity\ExerciseProgress;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** La page /classement, et le choix d'y paraître. */
final class LeaderboardTest extends WebTestCase
{
    use DatabaseTrait;

    private const array ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    private function learner(string $name, int $xp, bool $optIn, ?string $trackId = null, string $when = 'now'): User
    {
        $user = $this->createUser(strtolower($name).'@example.test', $name)->setLeaderboardOptIn($optIn);
        $this->success($user, $xp, $trackId, $when);

        return $user;
    }

    private function success(User $user, int $xp, ?string $trackId = null, string $when = 'now', string $exerciseId = 'exercice'): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $progress = new ExerciseProgress($user, $trackId, $exerciseId);
        $progress->saveDraft(['src/A.php' => '<?php'], 0, new \DateTimeImmutable($when));
        $progress->complete(0, $xp, new \DateTimeImmutable($when));
        $entityManager->persist($progress);
        $entityManager->flush();
    }

    /** @return list<string> le texte de chaque ligne de classé du tableau */
    private static function rows(Crawler $crawler): array
    {
        return $crawler->filter('.lb-table tbody tr:not(.lb-free)')->each(static fn (Crawler $row) => preg_replace('/\s+/', ' ', trim($row->text())));
    }

    public function testLeClassementNAfficheQueLesPseudosDeCeuxQuiLOntChoisi(): void
    {
        $this->learner('Ada', 50, optIn: true);
        $this->learner('Alan', 80, optIn: false);

        $crawler = $this->client->request('GET', '/classement?periode=tout');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['1 A Ada Pratique 1 50'], self::rows($crawler));
        $this->assertCount(2, $crawler->filter('.lb-table .lb-free'), 'Deux places libres avant le podium.');
        $this->assertCount(0, $crawler->filter('.lb-podium'));
        $this->assertStringContainsString('Le classement démarre', $crawler->filter('.lb-start')->text());
        $this->assertStringNotContainsString('Alan', $crawler->filter('main')->text());
        $this->assertStringNotContainsString('ada@example.test', (string) $this->client->getResponse()->getContent());
    }

    public function testUnePeriodeInconnueRevientAuMois(): void
    {
        $crawler = $this->client->request('GET', '/classement?periode=nimporte');

        $this->assertResponseIsSuccessful();
        $this->assertSame('Ce mois-ci', $crawler->filter('.lb-filters button[aria-pressed="true"]')->text());
    }

    public function testLApprenantChoisitDYParaitrePuisSEnRetire(): void
    {
        $this->learner('Ada', 50, optIn: true);
        $alan = $this->learner('Alan', 30, optIn: false);
        $this->client->loginUser($alan);

        $crawler = $this->client->request('GET', '/classement?periode=tout');
        $this->assertStringContainsString('Vous seriez 2e', preg_replace('/\s+/', ' ', $crawler->filter('.lb-me')->text()));

        $this->client->submitForm('Apparaître dans le classement', serverParameters: self::ORIGIN);
        $this->assertResponseRedirects('/classement');
        $this->assertTrue(static::getContainer()->get(UserRepository::class)->find($alan->getId())->isLeaderboardOptIn());
        $crawler = $this->client->followRedirect();
        $this->assertCount(2, self::rows($crawler));
        $this->assertStringContainsString('Encore 21 XP pour dépasser Ada.', preg_replace('/\s+/', ' ', $crawler->filter('.lb-me')->text()));

        $this->client->request('GET', '/compte');
        $this->client->submitForm('Me retirer du classement', serverParameters: self::ORIGIN);
        $this->assertResponseRedirects('/compte#classement');
        $this->assertFalse(static::getContainer()->get(UserRepository::class)->find($alan->getId())->isLeaderboardOptIn());
    }

    public function testLePodiumMontreLesTroisPremiersEtLeTableauLaSuite(): void
    {
        foreach (['Ada' => 90, 'Grace' => 70, 'Alan' => 50, 'Linus' => 30] as $name => $xp) {
            $this->learner($name, $xp, optIn: true);
        }

        $crawler = $this->client->request('GET', '/classement?periode=tout');

        $this->assertSame(['Grace', 'Ada', 'Alan'], $crawler->filter('.lb-podium > div > span.truncate')->each(static fn (Crawler $name) => $name->text()), 'Deuxième, premier, troisième.');
        $this->assertSame(['4 L Linus Pratique 1 30'], self::rows($crawler));
        $this->assertCount(0, $crawler->filter('.lb-start'));
    }

    public function testLeFiltreParParcoursNeCompteQueSesReussites(): void
    {
        $this->learner('Ada', 90, optIn: true);
        $grace = $this->learner('Grace', 40, optIn: true, trackId: 'decouverte');
        $this->success($grace, 30, exerciseId: 'autre');

        $crawler = $this->client->request('GET', '/classement?periode=tout&parcours=decouverte');
        $this->assertSame(['1 G Grace Découverte · Pratique 1 40'], self::rows($crawler));
        $this->assertSame('decouverte', $crawler->filter('select[name="parcours"] option[selected]')->attr('value'));

        $crawler = $this->client->request('GET', '/classement?periode=tout&parcours=pratique');
        $this->assertSame(['1 A Ada Pratique 1 90', '2 G Grace Découverte · Pratique 1 30'], self::rows($crawler));

        $crawler = $this->client->request('GET', '/classement?periode=tout&parcours=inconnu');
        $this->assertCount(2, self::rows($crawler), 'Un parcours inconnu ne filtre rien.');
    }

    public function testLeClassementDuMoisMontreLEvolutionDepuisLeMoisPrecedent(): void
    {
        $lastMonth = (new \DateTimeImmutable('first day of last month'))->setTime(12, 0)->format('Y-m-d H:i');
        // Le mois dernier : Ada, Ken, Linus, Grace. Ce mois-ci : Grace, Ken, Linus, Alan (nouveau), Ada.
        foreach (['Ada' => [90, 10], 'Ken' => [70, 35], 'Linus' => [60, 30], 'Grace' => [50, 40]] as $name => [$before, $now]) {
            $user = $this->learner($name, $before, optIn: true, when: $lastMonth);
            $this->success($user, $now, exerciseId: 'ce-mois');
        }
        $this->learner('Alan', 20, optIn: true);

        $crawler = $this->client->request('GET', '/classement?periode=mois');

        $this->assertSame(['4 A Alan Pratique Nouveau 1 20', '5 A Ada Pratique −4 places 1 10'], self::rows($crawler));
        $this->assertStringContainsString('Les évolutions comparent avec la fin', $crawler->filter('.lb-note')->text());
    }

    public function testLeChoixDemandeUnJetonCsrf(): void
    {
        $alan = $this->learner('Alan', 30, optIn: false);
        $this->client->loginUser($alan);

        $this->client->request('POST', '/compte/classement', ['participer' => '1', '_token' => 'faux'], server: self::ORIGIN);

        $this->assertResponseStatusCodeSame(403);
        $this->assertFalse(static::getContainer()->get(UserRepository::class)->find($alan->getId())->isLeaderboardOptIn());
    }

    /** Le classement anglais : mêmes classés, textes anglais, et le choix d'y paraître ramène sur la page anglaise. */
    public function testLeClassementExisteEnAnglais(): void
    {
        $this->learner('Ada', 50, optIn: true);
        $alan = $this->learner('Alan', 30, optIn: false);
        $this->client->loginUser($alan);

        $crawler = $this->client->request('GET', '/en/leaderboard?periode=tout');
        $this->assertResponseIsSuccessful();
        $this->assertSame('en', $crawler->filter('html')->attr('lang'));
        $this->assertSelectorTextContains('h1', 'Leaderboard');
        $this->assertStringContainsString('You would be 2nd', preg_replace('/\s+/', ' ', $crawler->filter('.lb-me')->text()));

        $this->client->submitForm('Join the leaderboard', serverParameters: self::ORIGIN);
        $this->assertResponseRedirects('/en/leaderboard');
        $crawler = $this->client->followRedirect();
        $this->assertCount(2, self::rows($crawler));
        $this->assertStringContainsString('21 more XP to overtake Ada.', preg_replace('/\s+/', ' ', $crawler->filter('.lb-me')->text()));
    }

    public function testUnInviteNePeutPasChoisir(): void
    {
        $this->client->request('POST', '/compte/classement', ['participer' => '1'], server: self::ORIGIN);

        $this->assertResponseRedirects('/connexion');
    }
}
