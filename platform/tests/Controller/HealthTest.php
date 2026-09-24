<?php

namespace App\Tests\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** /sante : ce que le HEALTHCHECK de l'image interroge. */
final class HealthTest extends WebTestCase
{
    public function testRepondOkQuandLaBaseRepond(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sante');

        $this->assertResponseIsSuccessful();
        $this->assertSame("ok\n", $client->getResponse()->getContent());
        $this->assertResponseHeaderSame('Cache-Control', 'no-store, private');
        // Sans session : un contrôle toutes les 30 secondes ne doit pas remplir le dossier des sessions.
        $this->assertResponseNotHasCookie('MOCKSESSID');
        $this->assertResponseNotHasCookie('PHPSESSID');
    }

    public function testRepond503SansDetailQuandLaBaseEstInjoignable(): void
    {
        $client = static::createClient();
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willThrowException($this->createStub(ConnectionException::class));
        static::getContainer()->set(Connection::class, $connection);

        $client->request('GET', '/sante');

        $this->assertResponseStatusCodeSame(503);
        $this->assertSame("base injoignable\n", $client->getResponse()->getContent());
    }

    public function testLeBacASableNeLeSertPas(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sante', server: ['HTTP_HOST' => '127.0.0.1:8001']);

        $this->assertResponseStatusCodeSame(404);
    }
}
