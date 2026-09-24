<?php

namespace App\Tests\Controller;

use App\Repository\FeedbackRepository;
use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use App\Tests\PaymentTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Un retour ne se poste que sur un exercice que le compte peut ouvrir, et en quantité raisonnable. */
final class FeedbackAccessTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;
    use PaymentTrait;

    private const array FEEDBACK = ['kind' => 'bug', 'message' => 'Le test 2 ne passe jamais.'];

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }

    public function testPasDeRetourSurUnChapitreFerme(): void
    {
        // « payant », tarifé : le deuxième chapitre demande l'achat du parcours.
        $this->usePaidPack();
        $client = static::createClient();
        $this->resetDatabase();
        $this->setPrice('payant', 7900);
        $client->loginUser($this->createUser());

        $this->json($client, 'POST', '/api/feedback/payant/e2', self::FEEDBACK);

        $this->assertResponseStatusCodeSame(403);
        $this->assertCount(0, static::getContainer()->get(FeedbackRepository::class)->findAll());
    }

    public function testUnParcoursEnPreparationNExistePas(): void
    {
        $this->usePacks(__DIR__.'/../Fixtures/packs/cohortes');
        $client = static::createClient();
        $this->resetDatabase();
        $client->loginUser($this->createUser());

        $this->json($client, 'POST', '/api/feedback/atelier-secret/e1', self::FEEDBACK);

        $this->assertResponseStatusCodeSame(404, 'Même réponse qu\'un exercice inconnu.');
    }

    public function testTropDeRetoursEnUneHeure(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $client->loginUser($user);
        // Les compteurs vivent dans un cache en mémoire, vidé entre deux requêtes de test : on épuise le quota avant
        // la première requête, sur le même kernel.
        $limiter = static::getContainer()->get('limiter.feedback')->create((string) $user->getId());
        for ($i = 1; $i <= 20; ++$i) {
            $limiter->consume();
        }

        $this->json($client, 'POST', '/api/feedback/decouverte/01-bonjour', self::FEEDBACK);

        $this->assertResponseStatusCodeSame(429);
        $this->assertCount(0, static::getContainer()->get(FeedbackRepository::class)->findAll());
    }
}
