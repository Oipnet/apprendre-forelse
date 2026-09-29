<?php

namespace App\Seo\Page;

use App\Content\Practice;
use App\Instance\Branding;
use App\Seo\PageSeo;
use App\Seo\SchemaOrg;

/**
 * L'index des notions, et la page d'une notion : le mot que l'on cherche, et ce qui le fait pratiquer.
 */
final readonly class ConceptSeo
{
    public function __construct(
        private PageSeo $seo,
        private SchemaOrg $schema,
        private Branding $branding,
    ) {
    }

    /**
     * L'index des notions.
     *
     * @param list<array{name: string, slug: string, exercises: int}> $concepts
     */
    public function index(array $concepts): void
    {
        $url = $this->schema->url('app_concepts');
        $this->seo
            ->setTitle('Les notions travaillées en exercices | '.$this->branding->name(), 'Les notions travaillées en exercices')
            ->setDescription(sprintf(
                '%d notions de développement, et pour chacune les exercices qui la font pratiquer : on écrit le code dans le navigateur, des tests disent s\'il est juste.',
                \count($concepts),
            ))
            ->setCanonical($url)
            ->addStructuredData($this->schema->breadcrumb([['Les notions', $url]]));
    }

    /**
     * Une notion : c'est le mot que l'on cherche dans un moteur, et la page qui rassemble ce qui le pratique.
     *
     * @param array{name: string, slug: string, exercises: list<array<string, mixed>>, practices: list<Practice>} $concept
     */
    public function concept(array $concept): void
    {
        $count = \count($concept['exercises']) + \count($concept['practices']);
        $this->seo
            ->setTitle(
                sprintf('%s : %d exercices pour la pratiquer | %s', $concept['name'], $count, $this->branding->name()),
                sprintf('%s : %d exercices pour la pratiquer', $concept['name'], $count),
                sprintf('%s en exercices', $concept['name']),
                $concept['name'],
            )
            ->setDescription(sprintf(
                'Les %d exercices qui font travailler %s : chacun pose un problème à résoudre en écrivant du code dans le navigateur, corrigé par des tests.',
                $count,
                $concept['name'],
            ))
            ->setCanonical($url = $this->schema->url('app_concept', ['slug' => $concept['slug']]))
            ->addStructuredData($this->schema->breadcrumb([
                ['Les notions', $this->schema->url('app_concepts')],
                [$concept['name'], $url],
            ]));
    }
}
