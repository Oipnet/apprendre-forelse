<?php

namespace App\EventListener;

use App\Content\ContentRepository;
use App\Content\Exercise;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Une page de parcours dans une langue où il n'est pas (encore) traduit renvoie (302) vers la même page en français,
 * plutôt qu'une 404 : une liste anglaise montre un parcours en français, et un lien vers lui suit la langue de la page.
 *
 * Seulement sur une 404, en GET et HEAD, hors de l'API ; temporaire, la traduction pouvant venir.
 */
final readonly class UntranslatedTrackRedirectListener
{
    public function __construct(
        private ContentRepository $content,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        if (!$event->getThrowable() instanceof NotFoundHttpException || !\is_string($route) || str_starts_with($route, 'api_') || !$request->isMethodSafe()) {
            return;
        }
        $parameters = (array) $request->attributes->get('_route_params', []);
        $locale = $parameters['_locale'] ?? null;
        $trackId = $parameters['trackId'] ?? null;
        if (!\is_string($locale) || Exercise::LOCALE === $locale || !\is_string($trackId)
            || null === $this->content->findTrack($trackId) || null !== $this->content->findTrack($trackId, $locale)) {
            return;
        }

        $url = $this->urls->generate($route, ['_locale' => Exercise::LOCALE] + $parameters);
        $query = $request->getQueryString();
        $event->allowCustomResponseCode();
        $event->setResponse(new RedirectResponse(null === $query ? $url : $url.'?'.$query));
    }
}
