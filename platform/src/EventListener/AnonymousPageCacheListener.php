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
        // La route avant l'utilisateur : lire l'utilisateur ouvre la session, interdite sur une route stateless (/sante).
        if (!$event->isMainRequest() || !$request->isMethodCacheable() || 200 !== $response->getStatusCode()
            || !\in_array($request->attributes->get('_route'), SearchIndexing::ROUTES, true)
            || $response->getEtag() || null !== $this->security->getUser()) {
            return;
        }

        $response->setEtag(hash('xxh128', (string) $response->getContent()));
        $response->isNotModified($request);
    }
}
