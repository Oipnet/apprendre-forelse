<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProgressApiTest extends WebTestCase
{
    use DatabaseTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    private function login(): User
    {
        $user = $this->createUser();
        $this->client->loginUser($user);

        return $user;
    }

    private function xpOf(User $user): int
    {
        return static::getContainer()->get(UserRepository::class)->find($user->getId())->getXp();
    }

    public function testUnInviteNePeutPasSauvegarder(): void
    {
        $this->json($this->client, 'PUT', '/api/progress/decouverte/01-bonjour', ['files' => []]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testBrouillonSauvegardeEtRelu(): void
    {
        $this->login();
        $this->json($this->client, 'GET', '/api/progress/decouverte/01-bonjour');
        $this->assertResponseStatusCodeSame(204, 'Aucune progression au départ.');

        $this->json($this->client, 'PUT', '/api/progress/decouverte/01-bonjour', [
            'files' => ['src/Controller/BonjourController.php' => '<?php // brouillon', 'config/services.yaml' => 'piraté'],
            'hintsUsed' => 1,
        ]);
        $this->assertResponseStatusCodeSame(204);

        $progress = $this->json($this->client, 'GET', '/api/progress/decouverte/01-bonjour');
        $this->assertSame(['src/Controller/BonjourController.php' => '<?php // brouillon'], $progress['files'], 'Seuls les fichiers éditables sont conservés.');
        $this->assertSame(1, $progress['hintsUsed']);
        $this->assertFalse($progress['completed']);
    }

    public function testLXpNEstAttribueeQuUneFoisEtTientCompteDesIndices(): void
    {
        $user = $this->login();
        $first = $this->json($this->client, 'POST', '/api/progress/decouverte/01-bonjour/complete', ['hintsUsed' => 1]);

        $this->assertSame(['xpEarned' => 38, 'totalXp' => 38, 'alreadyCompleted' => false], $first, '50 XP − 25 % pour un indice.');
        $second = $this->json($this->client, 'POST', '/api/progress/decouverte/01-bonjour/complete', ['hintsUsed' => 0]);
        $this->assertSame(['xpEarned' => 0, 'totalXp' => 38, 'alreadyCompleted' => true], $second);
        $this->assertSame(38, $this->xpOf($user));
    }

    public function testLaSolutionSeConsulteContreLXp(): void
    {
        $this->json($this->client, 'POST', '/api/progress/decouverte/01-bonjour/solution');
        $this->assertResponseStatusCodeSame(401, 'Un invité n\'a pas la solution.');

        $user = $this->login();
        $solution = $this->json($this->client, 'POST', '/api/progress/decouverte/01-bonjour/solution');
        $this->assertResponseIsSuccessful();
        $this->assertArrayHasKey('src/Controller/BonjourController.php', $solution['files']);
        $this->assertStringNotContainsString('TODO', $solution['files']['src/Controller/BonjourController.php']);

        $progress = $this->json($this->client, 'GET', '/api/progress/decouverte/01-bonjour');
        $this->assertTrue($progress['solutionRevealed']);
        $this->assertNull($progress['review']);

        $result = $this->json($this->client, 'POST', '/api/progress/decouverte/01-bonjour/complete', ['hintsUsed' => 0]);
        $this->assertSame(['xpEarned' => 0, 'totalXp' => 0, 'alreadyCompleted' => false], $result, 'La solution consultée ne rapporte pas l\'XP.');
        $this->assertSame(0, $this->xpOf($user));
        $this->assertTrue($this->json($this->client, 'GET', '/api/progress/decouverte/01-bonjour')['completed']);
    }

    public function testDonneesInvalides(): void
    {
        $this->login();
        $this->json($this->client, 'PUT', '/api/progress/decouverte/01-bonjour', ['files' => [], 'hintsUsed' => 999]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testLaProgressionDInviteEstRepriseApresConnexion(): void
    {
        $user = $this->login();
        $result = $this->json($this->client, 'POST', '/api/progress/import', [
            ['trackId' => 'decouverte', 'exerciseId' => '01-bonjour', 'files' => ['src/Controller/BonjourController.php' => '<?php // invité'], 'hintsUsed' => 0, 'completed' => true],
            // Tout le premier chapitre se joue sans compte : repris aussi.
            ['trackId' => 'decouverte', 'exerciseId' => '02-bonjour-prenom', 'files' => [], 'hintsUsed' => 0, 'completed' => true],
            // Parcours inconnu (un invité n'a pas pu jouer hors du premier chapitre) : ignoré.
            ['trackId' => 'decouverte', 'exerciseId' => 'inexistant', 'completed' => true],
        ]);

        $this->assertSame(['imported' => 2], $result);
        $this->assertSame(100, $this->xpOf($user));
        $progress = $this->json($this->client, 'GET', '/api/progress/decouverte/01-bonjour');
        $this->assertTrue($progress['completed']);

        // Un second import ne réattribue rien et n'écrase pas la progression du compte.
        $this->assertSame(['imported' => 0], $this->json($this->client, 'POST', '/api/progress/import', [
            ['trackId' => 'decouverte', 'exerciseId' => '01-bonjour', 'completed' => true],
        ]));
        $this->assertSame(100, $this->xpOf($user));
    }
}
