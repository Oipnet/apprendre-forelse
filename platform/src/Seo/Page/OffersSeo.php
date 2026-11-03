<?php

namespace App\Seo\Page;

use App\Seo\PageSeo;
use App\Seo\SchemaOrg;
use App\Theme\Theme;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * La page des offres : un Service par offre, avec son prix (Offer) quand le thème le donne en chiffres, rendu par la
 * personne du thème (même @id que l'auteur des articles), et la FAQ en FAQPage.
 */
final readonly class OffersSeo
{
    public function __construct(
        private PageSeo $seo,
        private SchemaOrg $schema,
        private Theme $theme,
        private TranslatorInterface $translator,
    ) {
    }

    /** @param array<string, mixed> $offers la page, de Theme::offers() */
    public function page(array $offers): void
    {
        $url = $this->schema->url('app_offers');
        // La personne du thème, ou l'organisation quand il n'en déclare pas.
        $provider = $this->schema->author();
        $this->seo
            ->setTitle(sprintf('%s | %s', $offers['seo']['title'] ?? $offers['hero']['title'], $this->theme->name()), $offers['hero']['title'])
            ->setDescription($offers['seo']['description'] ?? $offers['hero']['lead'] ?? $offers['hero']['title'])
            ->setCanonical($url);
        foreach ($offers['items'] as $item) {
            $this->seo->addStructuredData([
                '@type' => 'Service',
                '@id' => $url.'#'.$item['id'],
                'name' => $item['title'],
                ...(isset($item['audience']) ? ['description' => $item['audience']] : []),
                'url' => $url.'#'.$item['id'],
                'provider' => $provider,
                ...(isset($item['amount']) ? ['offers' => [
                    '@type' => 'Offer',
                    'price' => $item['amount'],
                    'priceCurrency' => 'EUR',
                    'url' => $url.'#'.$item['id'],
                ]] : []),
            ]);
        }
        if (isset($offers['faq']['items'])) {
            $this->seo->addStructuredData([
                '@type' => 'FAQPage',
                'mainEntity' => array_map(static fn (array $item) => [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
                ], $offers['faq']['items']),
            ]);
        }
        $this->seo->addStructuredData($this->schema->breadcrumb([[$this->translator->trans('offres.fil_ariane', [], 'blog'), $url]]));
        foreach (['fr', 'en'] as $locale) {
            if ($locale !== $this->seo->getLanguage() && $this->theme->speaks($locale) && $this->theme->offersIn($locale)) {
                $this->seo->addAlternate($locale, $this->schema->url('app_offers', ['_locale' => $locale]));
            }
        }
    }
}
