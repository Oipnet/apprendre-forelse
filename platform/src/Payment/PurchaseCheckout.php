<?php

namespace App\Payment;

use App\Content\Track;
use App\Controller\LegalController;
use App\Entity\Purchase;
use App\Entity\User;
use App\Repository\PurchaseRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Démarre le paiement d'un parcours, sans jamais ouvrir deux sessions Stripe payables pour le même achat : deux
 * onglets, ou un retour arrière depuis Stripe, donneraient sinon deux paiements aboutis (et un remboursement à faire).
 * Au plus un achat reste « en attente » par apprenant et par parcours :
 *  - sa session est encore ouverte, aux mêmes conditions : on y renvoie ;
 *  - elle vient d'être payée : on attend le webhook, sur la page de remerciement ;
 *  - elle a expiré, ou le prix (les CGV) a changé depuis : elle est close, l'achat abandonné, et un nouveau commence.
 */
final readonly class PurchaseCheckout
{
    public function __construct(
        private PaymentGateway $gateway,
        private EntityManagerInterface $entityManager,
        private PurchaseRepository $purchases,
        private UrlGeneratorInterface $urls,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return string l'adresse où envoyer l'apprenant : Stripe, ou la page de remerciement
     *
     * @throws PaymentException rien n'est alors enregistré
     */
    public function start(User $user, Track $track, PriceQuote $quote): string
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $track, $quote): string {
            // Verrou sur le compte : deux demandes simultanées (double clic, deux onglets) se suivent au lieu de se
            // croiser, et la seconde trouve l'achat en attente créé par la première.
            $this->entityManager->lock($user, LockMode::PESSIMISTIC_WRITE);

            $pending = $this->purchases->findPendingCheckout($user, $track->id);
            if (null !== $pending) {
                $session = $this->gateway->retrieveCheckoutSession((string) $pending->getStripeSessionId());
                if ($session->isComplete()) {
                    return $this->thanksUrl($pending);
                }
                if ($session->isOpen() && $pending->hasSameTerms($quote, LegalController::TERMS_VERSION)) {
                    return $session->url;
                }
                // Échoue si l'apprenant vient de payer dans l'autre onglet : rien n'est changé, il réessaie et
                // tombe alors sur la page de remerciement.
                if ($session->isOpen()) {
                    $this->gateway->expireCheckoutSession($session->id);
                }
                $pending->markAbandoned();
            }

            // Le prix est figé maintenant : celui que l'apprenant vient de voir, et que Stripe facturera.
            $purchase = new Purchase($user, $track->id, $quote->price, $quote->kind, WithdrawalWaiver::TEXT, $this->clock->now(), $quote->cohort, LegalController::TERMS_VERSION);
            $this->entityManager->persist($purchase);
            $this->entityManager->flush();

            $session = $this->gateway->createCheckoutSession(
                $purchase,
                sprintf('Parcours « %s » (accès à vie)', $track->title),
                $this->thanksUrl($purchase),
                $this->urls->generate('app_track', ['trackId' => $track->id], UrlGeneratorInterface::ABSOLUTE_URL),
            );
            $purchase->attachCheckoutSession($session->id);

            return $session->url;
        });
    }

    private function thanksUrl(Purchase $purchase): string
    {
        return $this->urls->generate('app_purchase_thanks', ['id' => $purchase->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
