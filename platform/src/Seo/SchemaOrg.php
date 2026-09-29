<?php

namespace App\Seo;

use App\Instance\Branding;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Les briques communes aux balises de toutes les pages : les URL absolues, l'éditeur du site, l'auteur des
 * contenus et le fil d'Ariane (schema.org). Chaque type de page (voir Seo\Page) les assemble à sa façon.
 */
final readonly class SchemaOrg
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private Branding $branding,
    ) {
    }

    /**
     * Une URL du site, vue de l'extérieur.
     *
     * @param array<string, mixed> $parameters
     */
    public function url(string $route, array $parameters = []): string
    {
        return $this->urls->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** Un chemin du site (image, logo) vu de l'extérieur, pour les données structurées. */
    public function absolute(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : rtrim($this->url('app_home'), '/').$path;
    }

    /** @return array<string, mixed> l'éditeur du site */
    public function organization(): array
    {
        return ['@type' => 'Organization', '@id' => $this->url('app_home').'#organisation', 'name' => $this->branding->name(), 'url' => $this->url('app_home')];
    }

    /**
     * L'auteur des parcours et des exercices : la personne que déclare la marque du moteur (voir son marque.yaml).
     * Une autre instance publie son organisation.
     *
     * @return array<string, mixed>
     */
    public function author(): array
    {
        if (null === ($person = $this->branding->person())) {
            return ['@id' => $this->url('app_home').'#organisation'];
        }

        return [
            '@type' => 'Person',
            '@id' => $this->url('app_home').'#auteur',
            'name' => $person['name'],
            'jobTitle' => $person['jobTitle'],
            'worksFor' => ['@id' => $this->url('app_home').'#organisation'],
            'url' => $this->branding->url(),
        ];
    }

    /**
     * @param list<array{string, string}> $trail [nom, adresse], après l'accueil : une liste, car deux étapes peuvent
     *                                          porter le même nom (un exercice titré comme son chapitre)
     *
     * @return array<string, mixed>
     */
    public function breadcrumb(array $trail): array
    {
        $items = [];
        foreach ([['Accueil', $this->url('app_home')], ...$trail] as [$name, $url]) {
            $items[] = ['@type' => 'ListItem', 'position' => \count($items) + 1, 'name' => $name, 'item' => $url];
        }

        return ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }
}
