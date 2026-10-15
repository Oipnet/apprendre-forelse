<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BonjourTest extends WebTestCase
{
    public function testThePageResponds(): void
    {
        static::createClient()->request('GET', '/hello');

        $this->assertResponseIsSuccessful('The /hello page must respond with a 200 status code.');
    }

    public function testThePageSaysHello(): void
    {
        $client = static::createClient();
        $client->request('GET', '/hello');

        // Exact content: Symfony's error page quotes the test code, a plain "contains" would be misleading.
        $this->assertSame('Hello Symfony!', trim((string) $client->getResponse()->getContent()), 'The page must display “Hello Symfony!”.');
    }
}
