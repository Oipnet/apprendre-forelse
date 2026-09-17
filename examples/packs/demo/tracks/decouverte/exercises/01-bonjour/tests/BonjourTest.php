<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BonjourTest extends WebTestCase
{
    public function testLaPageRepond(): void
    {
        static::createClient()->request('GET', '/bonjour');

        $this->assertResponseIsSuccessful('La page /bonjour doit répondre avec un code 200.');
    }

    public function testLaPageDitBonjour(): void
    {
        $client = static::createClient();
        $client->request('GET', '/bonjour');

        // Contenu exact : la page d'erreur de Symfony cite le code du test, un simple « contient » serait trompeur.
        $this->assertSame('Bonjour Symfony !', trim((string) $client->getResponse()->getContent()), 'La page doit afficher « Bonjour Symfony ! ».');
    }
}
