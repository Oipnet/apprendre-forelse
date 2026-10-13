<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BonjourPrenomTest extends WebTestCase
{
    public function testLaPageSaluePrenom(): void
    {
        $client = static::createClient();
        $client->request('GET', '/bonjour/Ada');

        $this->assertResponseIsSuccessful('La page /bonjour/Ada doit répondre avec un code 200.');
        $this->assertStringContainsString('Bonjour Ada !', (string) $client->getResponse()->getContent(), 'La page doit afficher « Bonjour Ada ! ».');
    }

    public function testLaPageSansPrenomFonctionneToujours(): void
    {
        $client = static::createClient();
        $client->request('GET', '/bonjour');

        // Le code d'abord : une page d'erreur peut citer le texte attendu (le code du test, en mode debug).
        $this->assertResponseIsSuccessful('La page /bonjour de l\'exercice précédent doit toujours répondre avec un code 200.');
        $this->assertStringContainsString('Bonjour Symfony !', (string) $client->getResponse()->getContent(), 'La page /bonjour de l\'exercice précédent doit toujours fonctionner.');
    }
}
