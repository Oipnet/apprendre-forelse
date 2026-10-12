<?php

namespace App\Tests\Controller;

use App\Entity\ExerciseProgress;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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

    private function learner(string $name, int $xp, bool $optIn): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser(strtolower($name).'@example.test', $name)->setLeaderboardOptIn($optIn);
        $progress = new ExerciseProgress($user, null, 'exercice');
        $progress->saveDraft(['src/A.php' => '<?php'], 0, new \DateTimeImmutable());
        $progress->complete(0, $xp, new \DateTimeImmutable());
        $entityManager->persist($progress);
        $entityManager->flush();

        return $user;
    }

    public function testLeClassementNAfficheQueLesPseudosDeCeuxQuiLOntChoisi(): void
    {
        $this->learner('Ada', 50, optIn: true);
        $this->learner('Alan', 80, optIn: false);

        $crawler = $this->client->request('GET', '/classement?periode=tout');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['1 Ada 1 50'], $crawler->filter('.lb-table tbody tr')->each(static fn ($row) => preg_replace('/\s+/', ' ', trim($row->text()))));
        $this->assertStringNotContainsString('Alan', $crawler->filter('main')->text());
        $this->assertStringNotContainsString('ada@example.test', (string) $this->client->getResponse()->getContent());
    }

    public function testUnePeriodeInconnueRevientAuMois(): void
    {
        $crawler = $this->client->request('GET', '/classement?periode=nimporte');

        $this->assertResponseIsSuccessful();
        $this->assertSame('Ce mois-ci', $crawler->filter('[aria-current="page"]')->text());
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
        $this->assertCount(2, $crawler->filter('.lb-table tbody tr'));

        $this->client->request('GET', '/compte');
        $this->client->submitForm('Me retirer du classement', serverParameters: self::ORIGIN);
        $this->assertResponseRedirects('/compte#classement');
        $this->assertFalse(static::getContainer()->get(UserRepository::class)->find($alan->getId())->isLeaderboardOptIn());
    }

    public function testLeChoixDemandeUnJetonCsrf(): void
    {
        $alan = $this->learner('Alan', 30, optIn: false);
        $this->client->loginUser($alan);

        $this->client->request('POST', '/compte/classement', ['participer' => '1', '_token' => 'faux'], server: self::ORIGIN);

        $this->assertResponseStatusCodeSame(403);
        $this->assertFalse(static::getContainer()->get(UserRepository::class)->find($alan->getId())->isLeaderboardOptIn());
    }

    public function testUnInviteNePeutPasChoisir(): void
    {
        $this->client->request('POST', '/compte/classement', ['participer' => '1'], server: self::ORIGIN);

        $this->assertResponseRedirects('/connexion');
    }
}
