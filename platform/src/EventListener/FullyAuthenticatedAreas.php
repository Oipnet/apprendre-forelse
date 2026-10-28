<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * /admin et /atelier demandent une vraie connexion : un visiteur seulement reconnu par le cookie « Rester connecté » (un
 * appareil oublié allumé, un cookie volé) se reconnecte d'abord. Le pare-feu transforme le refus en page de connexion.
 *
 * Ici plutôt que dans access_control : y combiner un rôle et IS_AUTHENTICATED_FULLY demande ExpressionLanguage, et
 * plusieurs rôles sur une règle valent « l'un ou l'autre ». Après le pare-feu (priorité 8), qui a déjà vérifié le rôle.
 */
final readonly class FullyAuthenticatedAreas
{
    private const string PATHS = '#^/(admin|atelier)(/|$)#';

    public function __construct(
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()
            && 1 === preg_match(self::PATHS, $event->getRequest()->getPathInfo())
            && $this->authorization->isGranted('IS_REMEMBERED')
            && !$this->authorization->isGranted('IS_AUTHENTICATED_FULLY')) {
            throw new AccessDeniedException('Une vraie connexion est demandée ici, pas seulement « Rester connecté ».');
        }
    }
}
