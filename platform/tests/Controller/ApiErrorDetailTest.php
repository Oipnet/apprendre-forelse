<?php

namespace App\Tests\Controller;

use App\Ai\ModelClient;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/** Hors debug (comme en prod), les refus de l'API gardent l'explication écrite pour l'apprenant. */
final class ApiErrorDetailTest extends WebTestCase
{
    use DatabaseTrait;

    public function testLeRefusDuMentorDitPourquoi(): void
    {
        $client = static::createClient(['debug' => false]);
        $this->resetDatabase();
        static::getContainer()->set(ModelClient::class, new ModelClient(new MockHttpClient(), 'cle-de-test', 'claude-sonnet-5'));
        $client->loginUser($this->createUser());

        $erreur = $this->json($client, 'POST', '/api/mentor/decouverte/01-bonjour/explain', ['error' => 'Erreur', 'source' => 'tests']);

        $this->assertResponseStatusCodeSame(428);
        $this->assertStringStartsWith('Confirmez votre adresse email', $erreur['detail'], 'Pas seulement « Precondition Required ».');
        $this->assertArrayNotHasKey('trace', $erreur);
    }

    public function testUneErreurDuFrameworkResteGenerique(): void
    {
        $client = static::createClient(['debug' => false]);
        $erreur = $this->json($client, 'POST', '/api/nulle-part');

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame('Not Found', $erreur['detail'], 'Le message du routeur (méthode, chemin) ne s\'affiche qu\'en debug.');
    }
}
