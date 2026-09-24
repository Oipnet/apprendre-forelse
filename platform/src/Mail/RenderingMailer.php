<?php

namespace App\Mail;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\BodyRendererInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Rend les gabarits d'un email avant qu'il parte dans la file d'attente de Messenger.
 *
 * Sans cela, le worker recevrait l'email avec son contexte (User, Purchase, Track…) sérialisé tel quel,
 * et le rendrait hors de toute requête. Rendu ici, le contexte est vidé (TemplatedEmail::markAsRendered())
 * et le worker n'a plus qu'à envoyer du HTML et du texte.
 */
#[AsDecorator(MailerInterface::class)]
final readonly class RenderingMailer implements MailerInterface
{
    public function __construct(
        #[AutowireDecorated]
        private MailerInterface $inner,
        private BodyRendererInterface $renderer,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if ($message instanceof TemplatedEmail && !$message->isRendered()) {
            $this->renderer->render($message);
        }

        $this->inner->send($message, $envelope);
    }
}
