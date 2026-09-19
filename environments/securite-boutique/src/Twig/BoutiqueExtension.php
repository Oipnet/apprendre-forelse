<?php

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Filtres et fonctions d'affichage de la boutique.
 */
final class BoutiqueExtension extends AbstractExtension
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('prix', $this->prix(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('panier_json', $this->panierJson(...)),
            new TwigFunction('terme_recherche', $this->termeRecherche(...)),
            new TwigFunction('nb_panier', $this->nbPanier(...)),
        ];
    }

    public function prix(string|float $valeur): string
    {
        return number_format((float) $valeur, 2, ',', ' ').' €';
    }

    /** Le panier, encodé en JSON, injecté tel quel dans un <script> (chapitre 3, F3.4). */
    public function panierJson(): string
    {
        $panier = $this->requestStack->getSession()->get('panier', []);

        return json_encode((object) $panier, JSON_THROW_ON_ERROR);
    }

    /** Le terme de recherche courant, réinjecté dans le script (chapitre 3, F3.4). */
    public function termeRecherche(): string
    {
        return (string) ($this->requestStack->getCurrentRequest()?->query->get('q', ''));
    }

    public function nbPanier(): int
    {
        return array_sum($this->requestStack->getSession()->get('panier', []));
    }
}
