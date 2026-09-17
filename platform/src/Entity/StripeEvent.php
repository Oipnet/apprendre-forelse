<?php

namespace App\Entity;

use App\Repository\StripeEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal des événements reçus sur le webhook Stripe (signature vérifiée) : un par identifiant d'événement.
 * Sert à ne pas traiter deux fois le même événement, à diagnostiquer, et à rejouer à la main
 * (commande app:stripe:rejouer).
 */
#[ORM\Entity(repositoryClass: StripeEventRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_STRIPE_EVENT', fields: ['eventId'])]
class StripeEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    /** Nombre de réceptions (Stripe réessaie, ou quelqu'un rejoue). */
    #[ORM\Column(options: ['default' => 1])]
    private int $deliveries = 1;

    /** Dernière erreur de traitement, pour le rejeu. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    public function __construct(
        #[ORM\Column(length: 255)]
        private string $eventId,
        #[ORM\Column(length: 100)]
        private string $type,
        /** Corps JSON brut de l'événement. */
        #[ORM\Column(type: Types::TEXT)]
        private string $payload,
        \DateTimeImmutable $now,
    ) {
        $this->receivedAt = $now;
    }

    public function redelivered(): void
    {
        ++$this->deliveries;
    }

    public function markProcessed(\DateTimeImmutable $now): void
    {
        $this->processedAt = $now;
        $this->error = null;
    }

    public function markFailed(string $error): void
    {
        $this->error = $error;
    }

    public function isProcessed(): bool
    {
        return null !== $this->processedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function getDeliveries(): int
    {
        return $this->deliveries;
    }

    public function getError(): ?string
    {
        return $this->error;
    }
}
