<?php

namespace App\Monitoring;

use App\Version;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Dernier regard sur une erreur avant son envoi à Sentry (option `before_send`, config/packages/sentry.yaml).
 *
 * Le bundle signale toute exception qui traverse le noyau, y compris celles qui ne sont que la réponse normale
 * à une requête : une page introuvable, un accès refusé, une session expirée. Elles noieraient les vraies pannes
 * (et le quota) : seules les erreurs du serveur partent. Chaque erreur porte aussi la version du moteur, pour
 * savoir quelle version l'a introduite ou corrigée.
 */
final class FiltreSentry
{
    public function __construct(private readonly Version $version)
    {
    }

    public function __invoke(Event $event, ?EventHint $hint): ?Event
    {
        $exception = $hint?->exception;
        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return null;
        }
        // Le pare-feu les change en redirection vers la connexion, ou en 403 : pas des pannes non plus.
        if ($exception instanceof AccessDeniedException || $exception instanceof AuthenticationException) {
            return null;
        }

        $event->setRelease($this->version->get());

        return $event;
    }
}
