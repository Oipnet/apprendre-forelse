<?php

namespace App\Content;

use App\Scratch\ScratchLink;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Le Markdown d'un article : celui des fiches de cours (LessonRenderer), plus « Essayer ce code ». Un bloc dont
 * l'en-tête annonce un bac à sable (voir CodeSandbox) est suivi d'un lien qui l'y ouvre : le code part dans
 * l'adresse (#code=, voir ScratchLink), rien n'est stocké, et la page demande avant de l'exécuter.
 */
final class ArticleRenderer
{
    private readonly MarkdownConverter $converter;

    public function __construct(
        private readonly ScratchLink $links,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
        $environment = LessonRenderer::environment();
        $environment->addEventListener(DocumentParsedEvent::class, $this->addTryLinks(...));
        $this->converter = new MarkdownConverter($environment);
    }

    public function toHtml(string $markdown): string
    {
        return (string) $this->converter->convert($markdown);
    }

    /** Sous le h1 de la page, comme LessonRenderer::toHtmlUnderTitle. */
    public function toHtmlUnderTitle(string $markdown): string
    {
        return LessonRenderer::underTitle($markdown, $this->toHtml(...));
    }

    private function addTryLinks(DocumentParsedEvent $event): void
    {
        $blocks = [];
        $walker = $event->getDocument()->walker();
        while (null !== ($step = $walker->next())) {
            $node = $step->getNode();
            if ($step->isEntering() && $node instanceof FencedCode && null !== CodeSandbox::wordOf((string) $node->getInfo())) {
                $blocks[] = $node;
            }
        }
        foreach ($blocks as $block) {
            $word = (string) CodeSandbox::wordOf((string) $block->getInfo());
            // Le langage reste seul dans l'en-tête : la coloration ne voit pas le mot du bac à sable.
            $block->setInfo(trim(implode(' ', array_filter(preg_split('/\s+/', trim((string) $block->getInfo())) ?: [], static fn (string $w) => $w !== $word))));
            $sandbox = CodeSandbox::of($word);
            if (null === $sandbox) {
                continue; // content:check le signale ; la page garde le bloc, sans lien
            }
            try {
                $fragment = $this->links->encode([$sandbox->path => $block->getLiteral()]);
            } catch (\LengthException) {
                continue;
            }
            $label = $this->translator->trans('show.essayer', ['framework' => $sandbox->framework->label()], 'blog');
            $link = new Link($this->urls->generate('app_scratch_'.$sandbox->framework->value).'#'.$fragment);
            $link->appendChild(new Text($label));
            $link->data->set('attributes', [
                'class' => 'code-try-link',
                'data-umami-event' => 'article-essayer',
                'data-umami-event-framework' => $sandbox->framework->value,
            ]);
            $paragraph = new Paragraph();
            $paragraph->data->set('attributes', ['class' => 'code-try']);
            $paragraph->appendChild($link);
            $block->insertAfter($paragraph);
        }
    }
}
