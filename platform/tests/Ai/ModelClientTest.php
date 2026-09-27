<?php

namespace App\Tests\Ai;

use App\Ai\ModelClient;
use App\Ai\ModelUnavailableException;
use App\Content\ContentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Une panne du modèle se distingue d'une réponse de travers. */
final class ModelClientTest extends TestCase
{
    private const array OUTIL = ['name' => 'outil', 'description' => 'Un outil.', 'input_schema' => ['type' => 'object']];

    public function testSansCleLeModeleEstIndisponible(): void
    {
        $this->expectException(ModelUnavailableException::class);
        (new ModelClient(new MockHttpClient(), '', 'claude-sonnet-5'))->appeler('', [], self::OUTIL);
    }

    public function testUneApiInjoignableRendLeModeleIndisponible(): void
    {
        $client = new ModelClient(new MockHttpClient(new MockResponse('', ['error' => 'Connexion refusée.'])), 'cle', 'claude-sonnet-5');

        $this->expectException(ModelUnavailableException::class);
        $this->expectExceptionMessage('Le modèle n\'a pas répondu');
        $client->appeler('', [], self::OUTIL);
    }

    public function testUneErreurDeLApiRendLeModeleIndisponible(): void
    {
        $client = new ModelClient(new MockHttpClient(new JsonMockResponse(['error' => ['message' => 'Surchargé.']], ['http_code' => 529])), 'cle', 'claude-sonnet-5');

        $this->expectException(ModelUnavailableException::class);
        $this->expectExceptionMessage('Le modèle a refusé : Surchargé.');
        $client->appeler('', [], self::OUTIL);
    }

    public function testUneReponseSansOutilNEstPasUnePanne(): void
    {
        $client = new ModelClient(new MockHttpClient(new JsonMockResponse(['content' => [['type' => 'text', 'text' => 'Non.']]])), 'cle', 'claude-sonnet-5');

        try {
            $client->appeler('', [], self::OUTIL);
            $this->fail('Une réponse sans appel d\'outil doit être refusée.');
        } catch (ContentException $e) {
            $this->assertNotInstanceOf(ModelUnavailableException::class, $e);
        }
    }
}
