<?php

namespace App\Twig;

use App\Content\ConceptIndex;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `notion_url('Boucle Twig')` : l'adresse de la page de cette notion, ou null si elle n'en a pas — une notion
 * vue sur un seul exercice ne relie rien et reste une simple pastille (voir ConceptIndex).
 */
final class ConceptExtension extends AbstractExtension
{
    /** @var array<string, string|null>|null les adresses par notion, calculées une fois par requête */
    private ?array $urls = null;

    public function __construct(
        private readonly ConceptIndex $concepts,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('notion_url', $this->url(...))];
    }

    public function url(string $concept): ?string
    {
        if (null === $this->urls) {
            $this->urls = [];
            foreach ($this->concepts->all() as $entry) {
                $this->urls[$entry['slug']] = $this->router->generate('app_concept', ['slug' => $entry['slug']]);
            }
        }

        return $this->urls[ConceptIndex::slug($concept)] ?? null;
    }
}
