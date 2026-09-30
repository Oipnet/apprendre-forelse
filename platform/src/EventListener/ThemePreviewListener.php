<?php

namespace App\EventListener;

use App\Theme\ActiveTheme;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Une page vue en aperçu d'un thème n'est gardée par aucun cache : ni partagé, ni celui du navigateur, qui la
 * resservirait une fois l'aperçu quitté. La session n'est lue que si elle existe déjà (voir ActiveTheme).
 */
final class ThemePreviewListener
{
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession() || !$request->getSession()->has(ActiveTheme::PREVIEW_SESSION)) {
            return;
        }

        $response = $event->getResponse();
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
    }
}
