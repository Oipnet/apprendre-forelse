<?php

namespace App\Tests\Content;

use App\Content\ArticleRenderer;
use App\Scratch\ScratchLink;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ArticleRendererTest extends KernelTestCase
{
    public function testUnBlocAnnonceSOuvreDansLeBacASable(): void
    {
        $html = $this->renderer()->toHtml(<<<'MD'
            ```php sandbox=php
            <?php echo 'Bonjour';
            ```

            ```php sandbox=symfony:src/Controller/MenuController.php
            <?php // la carte
            ```

            ```php
            echo 'sans bac à sable';
            ```
            MD);

        preg_match_all('#<p class="code-try"><a class="code-try-link" data-umami-event="article-essayer" data-umami-event-framework="(\w+)" href="([^"]+)">([^<]+)</a></p>#', $html, $links, \PREG_SET_ORDER);
        $this->assertCount(2, $links, 'Un lien sous chaque bloc annoncé, pas sous les autres.');
        [, $framework, $href, $label] = $links[0];
        $this->assertSame('php', $framework);
        $this->assertSame('Essayer ce code dans le bac à sable PHP', $label);
        $this->assertStringStartsWith('/bac-a-sable/php#code=', $href);
        $this->assertSame(['files' => ['public/index.php' => "<?php echo 'Bonjour';\n"], 'packages' => []], (new ScratchLink())->decode($href), 'Sans chemin, le fichier ouvert du bac à sable.');
        $this->assertSame(['src/Controller/MenuController.php' => "<?php // la carte\n"], (new ScratchLink())->decode($links[1][2])['files'] ?? null);
        $this->assertStringStartsWith('/bac-a-sable/symfony#code=', $links[1][2]);
        $this->assertStringNotContainsString('sandbox=', $html, 'Le mot du bac à sable ne s\'affiche pas.');
        $this->assertSame(3, substr_count($html, '<pre data-lang="php"'), 'Les trois blocs restent colorés en PHP.');
    }

    public function testUnBacASableInconnuLaisseLeBlocSansLien(): void
    {
        $html = $this->renderer()->toHtml("```php sandbox=nuxt\necho 1;\n```");

        $this->assertStringNotContainsString('code-try', $html);
        $this->assertStringContainsString('<pre data-lang="php"', $html);
    }

    public function testLeFluxGardeLeCodeSansColoration(): void
    {
        $html = $this->renderer()->toFeedHtml(<<<'MD'
            # Le titre

            ```php sandbox=php
            <?php $login_throttling = __DIR__;
            ```
            MD);

        $this->assertStringContainsString('<pre><code class="language-php">&lt;?php $login_throttling = __DIR__;'."\n".'</code></pre>', $html, 'Un bloc de code brut : dev.to recopie son texte sans échapper les « _ ».');
        $this->assertStringNotContainsString('hl-', $html);
        $this->assertStringContainsString('class="code-try-link"', $html, 'Le lien vers le bac à sable reste.');
        $this->assertStringNotContainsString('<h1', $html, 'Sous le titre de la page, comme toHtmlUnderTitle.');
    }

    private function renderer(): ArticleRenderer
    {
        self::bootKernel();

        return self::getContainer()->get(ArticleRenderer::class);
    }
}
