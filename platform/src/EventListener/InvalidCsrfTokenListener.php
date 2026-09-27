<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;

/**
 * Un jeton refusé par #[IsCsrfTokenValid] répond 403, comme les vérifications faites à la main avant lui. Sans ce
 * relais, le pare-feu y verrait un échec d'authentification et renverrait un compte déjà connecté vers la connexion.
 * Priorité 2 : avant l'ExceptionListener du pare-feu (1).
 */
final class InvalidCsrfTokenListener
{
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 2)]
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if ($exception instanceof InvalidCsrfTokenException) {
            // Sans « previous » : le pare-feu remonte la chaîne des exceptions et retrouverait l'échec d'authentification.
            $event->setThrowable(new AccessDeniedHttpException($exception->getMessage()));
        }
    }
}
