<?php

namespace App\Seo\Page;

use App\Seo\PageSeo;
use App\Seo\SchemaOrg;
use App\Theme\Theme;
use Symfony\Contracts\Translation\TranslatorInterface;

/** La page d'un auteur : une ProfilePage dont la personne est celle que nomment ses articles (même @id). */
final readonly class AuthorSeo
{
    public function __construct(
        private PageSeo $seo,
        private SchemaOrg $schema,
        private Theme $theme,
        private TranslatorInterface $translator,
    ) {
    }

    /** @param array<string, mixed> $person un auteur de Theme::author() */
    public function page(array $person): void
    {
        $this->seo
            // « Arnaud Pointet, Développeur Symfony freelance à Bordeaux » : ce qu'on cherche pour trouver quelqu'un.
            ->setTitle(sprintf('%s, %s | %s', $person['name'], isset($person['location']) ? $this->translator->trans('auteur.titre_lieu', ['metier' => $person['jobTitle'], 'lieu' => $person['location']], 'blog') : $person['jobTitle'], $this->theme->name()), $person['name'])
            ->setDescription($person['bio'] ?? $person['jobTitle'])
            ->setCanonical($url = $this->schema->url('app_author', ['slug' => $person['id']]))
            ->setType('profile')
            ->addStructuredData([
                '@type' => 'ProfilePage',
                'url' => $url,
                'inLanguage' => $this->seo->getLanguage(),
                'mainEntity' => [
                    ...$this->schema->author($person),
                    ...(isset($person['bio']) ? ['description' => $person['bio']] : []),
                    ...(null === ($portrait = $person['portraitUrl']) ? [] : ['image' => $this->schema->absolute($portrait)]),
                    // Ce qu'il connaît, et où il travaille : ce que les moteurs relient à la personne.
                    ...([] === ($knows = [...$person['expertise'] ?? [], ...$person['skills'] ?? []]) ? [] : ['knowsAbout' => $knows]),
                    ...(isset($person['location']) ? ['address' => ['@type' => 'PostalAddress', 'addressLocality' => $person['location']]] : []),
                ],
            ])
            ->addStructuredData($this->schema->breadcrumb([[$this->translator->trans('auteur.fil_ariane', [], 'blog'), $url]]));
        foreach (['fr', 'en'] as $locale) {
            if ($locale !== $this->seo->getLanguage() && $this->theme->speaks($locale)) {
                $this->seo->addAlternate($locale, $this->schema->url('app_author', ['_locale' => $locale, 'slug' => $person['id']]));
            }
        }
    }
}
