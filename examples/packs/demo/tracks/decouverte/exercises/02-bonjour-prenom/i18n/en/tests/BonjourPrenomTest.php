<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BonjourPrenomTest extends WebTestCase
{
    public function testThePageGreetsByName(): void
    {
        $client = static::createClient();
        $client->request('GET', '/hello/Ada');

        $this->assertResponseIsSuccessful('The /hello/Ada page must respond with a 200 status code.');
        $this->assertStringContainsString('Hello Ada!', (string) $client->getResponse()->getContent(), 'The page must display “Hello Ada!”.');
    }

    public function testThePageWithoutANameStillWorks(): void
    {
        $client = static::createClient();
        $client->request('GET', '/hello');

        // Status code first: an error page can quote the expected text (the test code, in debug mode).
        $this->assertResponseIsSuccessful('The /hello page from the previous exercise must still respond with a 200 status code.');
        $this->assertStringContainsString('Hello Symfony!', (string) $client->getResponse()->getContent(), 'The /hello page from the previous exercise must still work.');
    }
}
