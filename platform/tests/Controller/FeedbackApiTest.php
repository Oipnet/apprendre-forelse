<?php

namespace App\Tests\Controller;

use App\Entity\FeedbackKind;
use App\Repository\FeedbackRepository;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FeedbackApiTest extends WebTestCase
{
    use DatabaseTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testUnInviteNePeutPasEnvoyerDeRetour(): void
    {
        $this->json($this->client, 'POST', '/api/feedback/decouverte/01-bonjour', ['kind' => 'bug', 'message' => 'Le test 2 ne passe jamais.']);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testUnRetourEstEnregistreAvecSonContexte(): void
    {
        $user = $this->createUser(cohort: 'iut-2026');
        $this->client->loginUser($user);

        $result = $this->json($this->client, 'POST', '/api/feedback/decouverte/01-bonjour', [
            'kind' => 'unclear',
            'message' => "  L'énoncé ne dit pas où créer le contrôleur.  ",
            'hintsUsed' => 1,
            'completed' => true,
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(['ok' => true], $result);
        $feedbacks = static::getContainer()->get(FeedbackRepository::class)->findByCohort('iut-2026');
        $this->assertCount(1, $feedbacks);
        $feedback = $feedbacks[0];
        $this->assertSame($user->getId(), $feedback->getUser()->getId());
        $this->assertSame('decouverte', $feedback->getTrackId());
        $this->assertSame('01-bonjour', $feedback->getExerciseId());
        $this->assertSame(FeedbackKind::Unclear, $feedback->getKind());
        $this->assertSame("L'énoncé ne dit pas où créer le contrôleur.", $feedback->getMessage());
        $this->assertSame(1, $feedback->getHintsUsed());
        $this->assertTrue($feedback->isCompleted());
        $this->assertSame([], static::getContainer()->get(FeedbackRepository::class)->findByCohort('autre'), 'Filtré par cohorte.');
    }

    public function testDonneesInvalides(): void
    {
        $this->client->loginUser($this->createUser());

        $this->json($this->client, 'POST', '/api/feedback/decouverte/01-bonjour', ['kind' => 'rage', 'message' => 'Grr']);
        $this->assertResponseStatusCodeSame(422, 'Type inconnu.');

        $this->json($this->client, 'POST', '/api/feedback/decouverte/01-bonjour', ['kind' => 'bug', 'message' => '   ']);
        $this->assertResponseStatusCodeSame(422, 'Message vide.');
    }

    public function testExerciceInconnu(): void
    {
        $this->client->loginUser($this->createUser());
        $this->json($this->client, 'POST', '/api/feedback/decouverte/inexistant', ['kind' => 'bug', 'message' => 'Hé.']);

        $this->assertResponseStatusCodeSame(404);
    }
}
