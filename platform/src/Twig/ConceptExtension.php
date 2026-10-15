<?php

namespace App\Twig;

use App\Content\ConceptIndex;
use App\Content\Exercise;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `notion_url('Boucle Twig')` : l'adresse de la page de cette notion, ou null si elle n'en a pas — une notion
 * vue sur un seul exercice ne relie rien et reste une simple pastille (voir ConceptIndex).
 *
 * Dans la langue de la page : sous /en, `notion_url('Controller')` mène à /en/concepts/controller, et une notion
 * restée en français (un parcours pas encore traduit) n'a pas de lien.
 */
final class ConceptExtension extends AbstractExtension implements ResetInterface
{
    /** @var array<string, array<string, string>>|null les adresses par langue puis par notion, calculées une fois par requête */
    private ?array $urls = null;

    public function __construct(
        private readonly ConceptIndex $concepts,
        private readonly UrlGeneratorInterface $router,
        private readonly ?LocaleSwitcher $locale = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('notion_url', $this->url(...))];
    }

    public function url(string $concept): ?string
    {
        // Une variante régionale (en_GB) compte pour sa langue.
        $locale = null === $this->locale ? Exercise::LOCALE : explode('_', $this->locale->getLocale())[0];
        if (!isset($this->urls[$locale])) {
            $this->urls[$locale] = [];
            foreach ($this->concepts->all($locale) as $entry) {
                $this->urls[$locale][$entry['slug']] = $this->router->generate('app_concept', ['_locale' => $locale, 'slug' => $entry['slug']]);
            }
        }

        return $this->urls[$locale][ConceptIndex::slug($concept, $locale)] ?? null;
    }

    /** Recalculées à la requête suivante, comme ConceptIndex : le contenu a pu changer. */
    public function reset(): void
    {
        $this->urls = null;
    }
}
