<?php

namespace App\Tests\Content;

use App\Content\ArticleOutline;
use PHPUnit\Framework\TestCase;

final class ArticleOutlineTest extends TestCase
{
    public function testChaqueIntertitreRecoitUneAncreEtLeSommaireLesListe(): void
    {
        $outline = ArticleOutline::of('<p>Intro</p><h2>Le mode « sudo »</h2><p>a</p><h2>Les <code>Voter</code></h2><h2>Le mode « sudo »</h2><h3>Détail</h3>');

        $this->assertStringContainsString('<h2 id="le-mode-sudo">Le mode « sudo »</h2>', $outline['html']);
        $this->assertStringContainsString('<h2 id="les-voter">Les <code>Voter</code></h2>', $outline['html'], 'Le code en ligne reste dans le titre.');
        $this->assertStringContainsString('<h2 id="le-mode-sudo-2">', $outline['html'], 'Deux intertitres identiques, deux ancres.');
        $this->assertStringContainsString('<h3>Détail</h3>', $outline['html'], 'Seuls les h2 font le sommaire.');
        $this->assertSame([
            ['id' => 'le-mode-sudo', 'title' => 'Le mode « sudo »'],
            ['id' => 'les-voter', 'title' => 'Les Voter'],
            ['id' => 'le-mode-sudo-2', 'title' => 'Le mode « sudo »'],
        ], $outline['sections']);
    }

    public function testUnArticleCourtNARienASommaire(): void
    {
        $outline = ArticleOutline::of('<h2>Un</h2><h2>Deux</h2>');

        $this->assertSame([], $outline['sections']);
        $this->assertStringContainsString('<h2 id="deux">', $outline['html'], 'Les ancres restent : un lien vers une section marche toujours.');
    }
}
