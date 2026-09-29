<?php

namespace App\Account;

use App\Entity\User;
use App\Payment\PaymentException;
use App\Payment\PaymentGateway;
use App\Repository\PurchaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Les écritures du compte par son titulaire : le mot de passe (depuis le compte ou le lien « mot de passe oublié ») et
 * la suppression. La session reste l'affaire du contrôleur : c'est lui qui reconnecte ou déconnecte.
 */
final readonly class AccountManagement
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $hasher,
        private PurchaseRepository $purchases,
        private PaymentGateway $gateway,
        private LoggerInterface $logger,
    ) {
    }

    public function changePassword(User $user, string $plainPassword): void
    {
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        $this->entityManager->flush();
    }

    /**
     * Progression, avis et accès partent avec le compte ; les achats restent, sans lien vers lui (pièces comptables).
     *
     * Un paiement encore ouvert chez Stripe est d'abord fermé : payé après la suppression, il n'ouvrirait aucun accès.
     * Si Stripe refuse (paiement déjà lancé, service injoignable), l'achat reste en attente : payé quand même, il est
     * remboursé à sa confirmation (voir PurchaseFulfillment::fulfill()).
     */
    public function delete(User $user): void
    {
        foreach ($this->purchases->findPendingCheckouts($user) as $purchase) {
            try {
                $this->gateway->expireCheckoutSession((string) $purchase->getStripeSessionId());
                $purchase->markAbandoned();
            } catch (PaymentException $e) {
                $this->logger->warning('Session Stripe non fermée à la suppression du compte : {message}', ['message' => $e->getMessage(), 'purchase' => $purchase->getId()]);
            }
        }
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }
}
