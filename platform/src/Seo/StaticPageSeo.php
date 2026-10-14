<?php

namespace App\Seo;

use App\Theme\Theme;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Pose les balises d'une page fixe déclarées par #[Seo] sur son action, juste avant qu'elle s'exécute.
 *
 * Si l'action lève une exception (un 404), les balises sont retirées : la page d'erreur ne doit pas se
 * présenter comme la page demandée.
 */
final readonly class StaticPageSeo
{
    public function __construct(
        private PageSeo $seo,
        private SchemaOrg $schema,
        private Theme $theme,
        private TranslatorInterface $translator,
        /** @var list<string> */
        #[Autowire('%kernel.enabled_locales%')]
        private array $locales,
    ) {
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS)]
    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $page = $event->getAttributes(Seo::class)[0] ?? null;
        if (!$page instanceof Seo) {
            return;
        }
        $request = $event->getRequest();
        $url = $this->schema->url((string) $request->attributes->get('_route'), $request->attributes->get('_route_params', []));
        $brand = $this->theme->name();
        $title = null === $page->domain ? $page->title : $this->translator->trans($page->title, [], $page->domain);
        $description = null === $page->domain
            ? str_replace('%marque%', $brand, $page->description)
            : $this->translator->trans($page->description, ['marque' => $brand], $page->domain);
        $this->seo
            ->setTitle($title.' | '.$brand, $title)
            ->setDescription($description)
            ->setCanonical($url);
        // Une route traduite (#[Route(['fr' => …, 'en' => …])]) porte sa langue : chaque version nomme les autres (hreflang).
        /** @var array<string, mixed> $parameters */
        $parameters = $request->attributes->get('_route_params', []);
        if (isset($parameters['_locale'])) {
            foreach ($this->locales as $locale) {
                if ($locale !== $parameters['_locale']) {
                    $this->seo->addAlternate($locale, $this->schema->url((string) $request->attributes->get('_route'), ['_locale' => $locale] + $parameters));
                }
            }
        }
        if (null !== $page->breadcrumb) {
            $breadcrumb = null === $page->domain ? $page->breadcrumb : $this->translator->trans($page->breadcrumb, [], $page->domain);
            $this->seo->addStructuredData($this->schema->breadcrumb([[$breadcrumb, $url]]));
        }
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 64)]
    public function onException(ExceptionEvent $event): void
    {
        $attributes = $event->getRequest()->attributes->get('_controller_attributes', []);
        if ($event->isMainRequest() && \is_array($attributes) && [] !== array_filter($attributes, static fn ($attribute) => $attribute instanceof Seo)) {
            $this->seo->reset();
        }
    }
}
