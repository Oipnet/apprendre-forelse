<?php

namespace App\Content;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Tempest\Highlight\CommonMark\CodeBlockRenderer;
use Tempest\Highlight\Highlighter;

/**
 * Rend le markdown d'une fiche de cours en HTML, côté serveur (page du chapitre, PDF).
 *
 * Même prudence que DOMPurify dans le playground : le HTML brut du pack est supprimé et les
 * liens autres que http(s)/mailto sont neutralisés. Les blocs de code sont colorés en classes
 * « hl-… » (tempest/highlight), stylées dans site.css. Le code en ligne reste tel quel : tempest
 * y lirait un préfixe « {…} » comme un choix de langage, et `{prenom}` disparaîtrait.
 */
final class LessonRenderer
{
    private readonly MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addRenderer(FencedCode::class, new CodeBlockRenderer(new Highlighter()), 10);
        $this->converter = new MarkdownConverter($environment);
    }

    public function toHtml(string $markdown): string
    {
        return (string) $this->converter->convert($markdown);
    }

    /**
     * Une consigne sous le h1 de sa page : le titre de tête du markdown, qui le répète, disparaît, et tout autre
     * titre de niveau 1 descend d'un cran (une page, un seul h1).
     */
    public function toHtmlUnderTitle(string $markdown): string
    {
        $markdown = (string) preg_replace('/\A\s*#[ \t][^\n]*\n/', '', $markdown);

        return (string) preg_replace('#<(/?)h1\b#', '<$1h2', $this->toHtml($markdown));
    }
}
