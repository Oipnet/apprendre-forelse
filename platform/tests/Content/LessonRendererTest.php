<?php

namespace App\Tests\Content;

use App\Content\LessonRenderer;
use PHPUnit\Framework\TestCase;

final class LessonRendererTest extends TestCase
{
    public function testRendLeMarkdownEnNeutralisantLeHtmlEtLesLiensDangereux(): void
    {
        $html = (new LessonRenderer())->toHtml(<<<'MD'
            ## Titre

            Un <script>alert(1)</script> et [un lien](javascript:alert(1)), puis [la doc](https://symfony.com/doc/current/routing.html).
            Un paramètre `{prenom}` en ligne.

            | Clé | Valeur |
            |-----|--------|
            | a   | b      |

            ```php
            #[Route('/bonjour')]
            public function index(): Response {}
            ```
            MD);

        $this->assertStringContainsString('<h2>Titre</h2>', $html);
        $this->assertStringNotContainsString('<script', $html, 'Le HTML brut du pack est supprimé.');
        $this->assertStringNotContainsString('javascript:', $html, 'Les liens dangereux sont neutralisés.');
        $this->assertStringContainsString('href="https://symfony.com/doc/current/routing.html"', $html);
        $this->assertStringContainsString('<table>', $html, 'Les tableaux GFM sont rendus.');
        $this->assertStringContainsString('<pre data-lang="php"', $html, 'Un bloc php est reconnu…');
        $this->assertStringContainsString('<span class="hl-keyword">function</span>', $html, '… et coloré.');
        $this->assertStringContainsString('<code>{prenom}</code>', $html, 'Le code en ligne n\'est pas interprété (« {…} » n\'est pas un langage).');
    }
}
