<?php

namespace App\Entity;

use App\Repository\PurchaseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Achat individuel d'un parcours (Stripe Checkout). Créé « en attente » avant la redirection vers Stripe ;
 * seul le webhook le passe « payé » et ouvre l'accès (voir PurchaseFulfillment).
 *
 * C'est une pièce comptable : supprimer le compte de l'apprenant (RGPD) garde l'achat, sans lien vers lui
 * (l'email est conservé à part).
 */
#[ORM\Entity(repositoryClass: PurchaseRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_PURCHASE_STRIPE_SESSION', fields: ['stripeSessionId'])]
#[ORM\Index(name: 'IDX_PURCHASE_TRACK_STATUS', fields: ['trackId', 'status'])]
class Purchase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    /** Email de l'apprenant au moment de l'achat : reste lisible si le compte est supprimé. */
    #[ORM\Column(length: 180)]
    private string $customerEmail;

    /** Cohorte (financée par ses apprenants) dont le tarif a été appliqué. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Cohort $cohort = null;

    #[ORM\Column(length: 20, enumType: PurchaseStatus::class)]
    private PurchaseStatus $status = PurchaseStatus::Pending;

    /** Montant réellement payé (TTC, en centimes), tel que Stripe l'a confirmé. */
    #[ORM\Column(nullable: true)]
    private ?int $amountPaid = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeSessionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    /** Reçu Stripe (page hébergée par Stripe). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $receiptUrl = null;

    /** Facture Stripe émise au paiement (Checkout, invoice_creation) ; null pour un achat antérieur aux factures. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeInvoiceId = null;

    /** Texte exact de la renonciation au droit de rétractation cochée par l'apprenant. */
    #[ORM\Column(type: Types::TEXT)]
    private string $withdrawalWaiverText;

    #[ORM\Column]
    private \DateTimeImmutable $withdrawalWaiverAcceptedAt;

    /** Version des conditions générales de vente acceptées (LegalController::TERMS_VERSION) ; null avant leur existence. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $termsVersion = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $termsAcceptedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $refundedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeRefundId = null;

    public function __construct(
        User $user,
        #[ORM\Column(length: 100)]
        private string $trackId,
        /** Prix appliqué (TTC, en centimes) au moment de créer la session Stripe. */
        #[ORM\Column]
        private int $price,
        #[ORM\Column(length: 20, enumType: PriceKind::class)]
        private PriceKind $priceKind,
        string $withdrawalWaiverText,
        \DateTimeImmutable $now,
        ?Cohort $cohort = null,
        ?string $termsVersion = null,
    ) {
        $this->user = $user;
        $this->termsVersion = $termsVersion;
        $this->termsAcceptedAt = null === $termsVersion ? null : $now;
        $this->customerEmail = (string) $user->getEmail();
        $this->withdrawalWaiverText = $withdrawalWaiverText;
        $this->withdrawalWaiverAcceptedAt = $this->createdAt = $now;
        $this->cohort = $cohort;
    }

    public function attachCheckoutSession(string $sessionId): void
    {
        $this->stripeSessionId = $sessionId;
    }

    /** Paiement confirmé. Renvoie false s'il l'était déjà (webhook rejoué). */
    public function markPaid(\DateTimeImmutable $now, ?int $amountPaid, ?string $paymentIntentId): bool
    {
        if (PurchaseStatus::Pending !== $this->status) {
            return false;
        }
        $this->status = PurchaseStatus::Paid;
        $this->paidAt = $now;
        $this->amountPaid = $amountPaid ?? $this->price;
        $this->stripePaymentIntentId = $paymentIntentId ?? $this->stripePaymentIntentId;

        return true;
    }

    public function attachInvoice(?string $invoiceId): void
    {
        $this->stripeInvoiceId = $invoiceId ?? $this->stripeInvoiceId;
    }

    public function getStripeInvoiceId(): ?string
    {
        return $this->stripeInvoiceId;
    }

    public function markRefunded(\DateTimeImmutable $now, ?string $refundId): void
    {
        $this->status = PurchaseStatus::Refunded;
        $this->refundedAt = $now;
        $this->stripeRefundId = $refundId;
    }

    public function isPaid(): bool
    {
        return PurchaseStatus::Paid === $this->status;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getCustomerEmail(): string
    {
        return $this->customerEmail;
    }

    public function getTrackId(): string
    {
        return $this->trackId;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function getPriceKind(): PriceKind
    {
        return $this->priceKind;
    }

    public function getCohort(): ?Cohort
    {
        return $this->cohort;
    }

    public function getStatus(): PurchaseStatus
    {
        return $this->status;
    }

    public function getAmountPaid(): ?int
    {
        return $this->amountPaid;
    }

    public function getStripeSessionId(): ?string
    {
        return $this->stripeSessionId;
    }

    public function getStripePaymentIntentId(): ?string
    {
        return $this->stripePaymentIntentId;
    }

    public function getReceiptUrl(): ?string
    {
        return $this->receiptUrl;
    }

    public function setReceiptUrl(?string $receiptUrl): void
    {
        $this->receiptUrl = $receiptUrl;
    }

    public function getWithdrawalWaiverText(): string
    {
        return $this->withdrawalWaiverText;
    }

    public function getWithdrawalWaiverAcceptedAt(): \DateTimeImmutable
    {
        return $this->withdrawalWaiverAcceptedAt;
    }

    public function getTermsVersion(): ?string
    {
        return $this->termsVersion;
    }

    public function getTermsAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->termsAcceptedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getRefundedAt(): ?\DateTimeImmutable
    {
        return $this->refundedAt;
    }

    public function getStripeRefundId(): ?string
    {
        return $this->stripeRefundId;
    }
}
