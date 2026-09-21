<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le voisinage d'un exercice sur sa page publique. Sans lui, un exercice ne mène qu'au parcours : les exercices
 * d'un même chapitre ne se connaissent pas, et tout pend d'une seule page.
 * Pack de test « payant » : chapitre « libre » (e1), puis « complet » (e2, e3).
 */
final class ExerciseSiblingsTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    protected function setUp(): void
    {
        $this->usePaidPack();
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }

    public function testLaPagePubliqueMeneAuxAutresExercicesDuChapitre(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $crawler = $client->request('GET', '/parcours/payant/e2');

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            ['/parcours/payant/e3'],
            $crawler->filter('.exercise-siblings-list a')->each(static fn ($node) => $node->attr('href')),
            'Le voisin du chapitre, et pas l\'exercice lui-même.',
        );
    }

    public function testLaPagePubliqueMeneAuSommaireDuChapitreEtAuParcours(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $crawler = $client->request('GET', '/parcours/payant/e2');

        $links = $crawler->filter('.exercise-siblings-more a')->each(static fn ($node) => $node->attr('href'));
        $this->assertSame(['/parcours/payant/chapitre/complet/sommaire', '/parcours/payant'], $links);
    }

    /** Le fil d'Ariane passe désormais par le chapitre, qui est devenu une page. */
    public function testLeFilDArianePasseParLeChapitre(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $crawler = $client->request('GET', '/parcours/payant/e2');

        $this->assertSame(
            ['/', '/parcours/payant', '/parcours/payant/chapitre/complet/sommaire'],
            $crawler->filter('.breadcrumb a')->each(static fn ($node) => $node->attr('href')),
        );
    }
}
