<?php

namespace App\EventListener;

use App\Seo\SearchIndexing;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * ETag sur les pages publiques vues sans compte : un robot ou un visiteur qui revient reçoit un 304 si rien n'a
 * changé. Pas de max-age : après connexion, la même adresse doit tout de suite montrer le compte.
 */
final readonly class AnonymousPageCacheListener
{
    public function __construct(private Security $security)
    {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        if (!$event->isMainRequest() || !$request->isMethodCacheable() || 200 !== $response->getStatusCode()
            || null !== $this->security->getUser() || $response->getEtag()
            || !\in_array($request->attributes->get('_route'), SearchIndexing::ROUTES, true)) {
            return;
        }

        $response->setEtag(hash('xxh128', (string) $response->getContent()));
        $response->isNotModified($request);
    }
}
