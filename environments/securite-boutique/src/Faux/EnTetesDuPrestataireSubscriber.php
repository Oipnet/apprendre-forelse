<?php

namespace App\Faux;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * FAILLE (chapitre 14, F14.4) : en-têtes serveur bavards, comme le nginx/PHP du
 * prestataire. Dans le bac à sable il n'y a pas de vrai serveur : on les simule.
 * Correction : supprimer ce subscriber.
 */
final class EnTetesDuPrestataireSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['ajouter', -100]];
    }

    public function ajouter(ResponseEvent $event): void
    {
        $reponse = $event->getResponse();
        $reponse->headers->set('X-Powered-By', 'PHP/8.4.3');
        $reponse->headers->set('Server', 'nginx/1.24.0 (Ubuntu)');
    }
}
