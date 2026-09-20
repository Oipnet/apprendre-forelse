<?php

namespace App\Security;

use App\Repository\ClientRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Relit le cookie « souvenir » à chaque requête et reconnecte le client.
 *
 * FAILLE (chapitre 7, F7.4 et chapitre 9, F9.5) : le « se souvenir de moi » est
 * fait main. Le cookie contient base64(email:hachage), non signé : quiconque le
 * fabrique se connecte. Et comme il est relu à chaque requête, il ressuscite une
 * session qu'on vient de fermer. Correction : remember_me de Symfony, signé, avec
 * signature_properties: ['password'], et effacement du cookie à la déconnexion.
 */
final class SouvenirSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ClientRepository $clients,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['reconnecter', 6]];
    }

    public function reconnecter(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || null !== $this->tokenStorage->getToken()) {
            return;
        }

        $cookie = $event->getRequest()->cookies->get('souvenir');
        if (!\is_string($cookie) || '' === $cookie) {
            return;
        }

        $decode = base64_decode($cookie, true);
        if (false === $decode || !str_contains($decode, ':')) {
            return;
        }

        [$email, $hachage] = explode(':', $decode, 2);
        $client = $this->clients->parEmail($email);
        if (null === $client || !hash_equals($client->getMotDePasse(), $hachage)) {
            return;
        }

        $this->tokenStorage->setToken(new UsernamePasswordToken($client, 'main', $client->getRoles()));
    }
}
