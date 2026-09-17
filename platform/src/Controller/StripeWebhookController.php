<?php

namespace App\Controller;

use App\Payment\PaymentException;
use App\Payment\StripeWebhook;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Point d'entrée des événements Stripe. Pas de session ni de jeton CSRF (appel de serveur à serveur) : la signature
 * Stripe-Signature fait foi. 400 pour un événement refusé ; 500 si le traitement échoue, pour que Stripe réessaie.
 */
final class StripeWebhookController
{
    #[Route('/paiement/stripe/webhook', name: 'app_stripe_webhook', methods: ['POST'], format: 'json')]
    public function __invoke(Request $request, StripeWebhook $webhook): Response
    {
        try {
            $event = $webhook->receive($request->getContent(), (string) $request->headers->get('Stripe-Signature'));
        } catch (PaymentException $e) {
            return new Response($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        try {
            $webhook->process($event);
        } catch (\Throwable) {
            return new Response('Traitement en échec, noté dans le journal des événements.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
