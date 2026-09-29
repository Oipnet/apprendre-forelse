<?php

namespace App\EventListener;

use App\Content\ContentRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Un parcours ou un exercice renommé garde ses adresses : une page introuvable dont l'identifiant figure dans les
 * « former_ids » d'un parcours ou d'un exercice redirige (301) vers la même page, sous l'identifiant actuel. Les liens
 * partagés, les favoris et les moteurs de recherche suivent.
 *
 * Seulement sur une 404 : un identifiant actuel l'emporte toujours. Seulement en GET et HEAD, et hors de l'API.
 */
final readonly class FormerIdRedirectListener
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
        $current = $parameters;

        if (\is_string($parameters['trackId'] ?? null) && null === $this->content->findTrack($parameters['trackId'])) {
            $current['trackId'] = $this->content->currentTrackId($parameters['trackId']) ?? $parameters['trackId'];
        }
        if (\is_string($parameters['exerciseId'] ?? null)) {
            $trackId = $current['trackId'] ?? null;
            $current['exerciseId'] = match (true) {
                \is_string($trackId) && null === $this->content->findExercise($trackId, $parameters['exerciseId']) => $this->content->currentExerciseId($trackId, $parameters['exerciseId']),
                !\is_string($trackId) && null === $this->content->findPractice($parameters['exerciseId']) => $this->content->currentPracticeId($parameters['exerciseId']),
                default => null,
            } ?? $parameters['exerciseId'];
        }
        if ($current === $parameters) {
            return;
        }

        $url = $this->urls->generate($route, $current);
        $query = $request->getQueryString();
        $event->allowCustomResponseCode();
        $event->setResponse(new RedirectResponse(null === $query ? $url : $url.'?'.$query, Response::HTTP_MOVED_PERMANENTLY));
    }
}
