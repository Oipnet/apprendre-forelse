<?php

namespace App\Tests\Search;

use App\Search\SearchCatalog;
use App\Search\SearchDocument;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Ce que la recherche connaît du pack de démo : les pages publiques, dans chaque langue, sans les indices. */
final class SearchCatalogTest extends KernelTestCase
{
    public function testLesPagesPubliquesEntrentDansLIndexFrancais(): void
    {
        $documents = static::getContainer()->get(SearchCatalog::class)->documents('fr');
        $byId = [];
        foreach ($documents as $document) {
            $byId[$document->id()] = $document;
        }

        $practice = $byId['pratique-exemple-map-request-header'] ?? null;
        $this->assertNotNull($practice, 'L\'exercice de Pratique paru est dans l\'index.');
        $this->assertSame('Lire un en-tête avec #[MapRequestHeader]', $practice->title);
        $this->assertSame('/pratique/exemple-map-request-header', $practice->url);
        $this->assertSame(['Contrôleur', 'HttpKernel'], $practice->keywords);
        $this->assertStringContainsString('Nouveau dans Symfony 8.1.', $practice->text, 'La consigne, en texte brut.');
        $this->assertStringContainsString('MapRequestPayload', $practice->text, 'Le code compte : on le cherche aussi.');
        $this->assertStringNotContainsString('**', $practice->text, 'Plus de Markdown.');
        $this->assertStringNotContainsString('Remplacez l\'argument', $practice->text, 'Les indices ne sont pas dans l\'index : la recherche ne donne pas la réponse.');

        $this->assertSame('/notions/controleur', ($byId['notion-controleur'] ?? null)?->url);
        $this->assertSame('/parcours/decouverte', ($byId['parcours-decouverte'] ?? null)?->url);
        $this->assertSame('/parcours/decouverte/chapitre/bonjour/sommaire', ($byId['chapitre-decouverte_bonjour'] ?? null)?->url);
        $this->assertSame('Découverte · Bonjour Symfony', ($byId['exercice-decouverte_01-bonjour'] ?? null)?->label);

        foreach ($documents as $document) {
            $this->assertContains($document->type, SearchDocument::TYPES);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $document->id(), 'Un identifiant que Meilisearch accepte.');
        }
        $this->assertSame(array_unique(array_map(static fn (SearchDocument $d) => $d->id(), $documents)), array_map(static fn (SearchDocument $d) => $d->id(), $documents), 'Pas deux documents sous le même identifiant.');
    }

    public function testLIndexAnglaisALesPagesTraduitesSousLeursAdressesAnglaises(): void
    {
        $documents = static::getContainer()->get(SearchCatalog::class)->documents('en');
        $urls = array_map(static fn (SearchDocument $d) => $d->url, $documents);

        $this->assertContains('/en/practice/exemple-map-request-header', $urls);
        $this->assertContains('/en/concepts/controller', $urls);
        $titles = array_map(static fn (SearchDocument $d) => $d->title, $documents);
        $this->assertContains('Read a header with #[MapRequestHeader]', $titles);
        foreach ($urls as $url) {
            $this->assertStringStartsWith('/en/', $url);
        }
    }
}
