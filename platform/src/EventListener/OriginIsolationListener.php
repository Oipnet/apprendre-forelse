<?php

namespace App\EventListener;

use App\Security\SandboxOrigin;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Cloisonne les deux origines servies par la même application :
 *  - le bac à sable ne sert que le relais d'aperçu (aucune page, aucune API de la plateforme) ;
 *  - la plateforme ne sert jamais le relais (le Service Worker ne doit pas s'y installer).
 * Ajoute aussi les en-têtes de sécurité (qui peut encadrer quelle page, HTTPS obligatoire).
 */
final readonly class OriginIsolationListener
{
    public function __construct(private SandboxOrigin $sandbox)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $isRelay = SandboxOrigin::RELAY_PATH === $request->getPathInfo();
        // robots.txt existe sur les deux origines : le bac à sable y interdit tout (voir SeoController).
        if ('/robots.txt' !== $request->getPathInfo() && $this->sandbox->isSandboxRequest($request) !== $isRelay) {
            throw new NotFoundHttpException();
        }
        // Écritures : refusées si le navigateur annonce une autre origine que la page (bac à sable compris).
        $origin = $request->headers->get('Origin');
        if (!$request->isMethodSafe() && null !== $origin && $origin !== $request->getSchemeAndHttpHost()) {
            throw new AccessDeniedHttpException('Origine non autorisée.');
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $headers = $event->getResponse()->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Le relais ne peut être encadré que par la plateforme ; les pages de la plateforme par personne d'autre.
        $ancestors = $this->sandbox->isSandboxRequest($event->getRequest()) ? $this->sandbox->platformOrigin : "'self'";
        $headers->set('Content-Security-Policy', 'frame-ancestors '.$ancestors);
        // HTTPS seulement, et jamais pour une adresse locale : le navigateur retiendrait « localhost en HTTPS »
        // pour tous les projets de la machine. Pas d'includeSubDomains : les autres sous-domaines ne dépendent pas d'ici.
        $request = $event->getRequest();
        if ($request->isSecure() && !self::isLocalHost($request->getHost())) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
    }

    private static function isLocalHost(string $host): bool
    {
        return 'localhost' === $host || str_ends_with($host, '.localhost') || false !== filter_var(trim($host, '[]'), \FILTER_VALIDATE_IP);
    }
}
