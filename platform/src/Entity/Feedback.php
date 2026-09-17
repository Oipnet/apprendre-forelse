<?php

namespace App\Entity;

use App\Repository\FeedbackRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Retour d'un apprenant sur un exercice (bouton « Un avis ? » du playground).
 * Comme la progression, l'exercice n'est référencé que par ses identifiants.
 */
#[ORM\Entity(repositoryClass: FeedbackRepository::class)]
class Feedback
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Traité par l'équipe (tableau de bord d'administration). */
    #[ORM\Column(options: ['default' => false])]
    private bool $handled = false;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'feedbacks')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        /** null pour un exercice de Pratique */
        #[ORM\Column(length: 100, nullable: true)]
        private ?string $trackId,
        #[ORM\Column(length: 100)]
        private string $exerciseId,
        #[ORM\Column(enumType: FeedbackKind::class)]
        private FeedbackKind $kind,
        #[ORM\Column(type: Types::TEXT)]
        private string $message,
        /** Indices consultés au moment de l'avis. */
        #[ORM\Column(options: ['default' => 0])]
        private int $hintsUsed = 0,
        /** L'exercice était-il déjà réussi au moment de l'avis ? */
        #[ORM\Column(options: ['default' => false])]
        private bool $completed = false,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTrackId(): ?string
    {
        return $this->trackId;
    }

    public function getExerciseId(): string
    {
        return $this->exerciseId;
    }

    public function getKind(): FeedbackKind
    {
        return $this->kind;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getHintsUsed(): int
    {
        return $this->hintsUsed;
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isHandled(): bool
    {
        return $this->handled;
    }

    public function setHandled(bool $handled): static
    {
        $this->handled = $handled;

        return $this;
    }

    public function __toString(): string
    {
        return sprintf('%s · %s', $this->exerciseId, $this->kind->label());
    }
}
