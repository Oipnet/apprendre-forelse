<?php

namespace App\Entity;

use App\Repository\ContactMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un message du formulaire de contact (ou de la page Écoles et entreprises). Gardé en base en plus de l'email à
 * l'équipe : un envoi en échec ne perd pas la demande. Lu, marqué traité ou supprimé dans l'admin.
 */
#[ORM\Entity(repositoryClass: ContactMessageRepository::class)]
#[ORM\Index(name: 'IDX_CONTACT_MESSAGE_HANDLED', fields: ['handled', 'createdAt'])]
class ContactMessage implements Handleable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: ContactSubject::class)]
    #[Assert\NotNull(message: 'contact.objet.vide')]
    private ?ContactSubject $subject = ContactSubject::Question;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'contact.nom.vide')]
    #[Assert\Length(max: 100)]
    private ?string $name = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'contact.email.vide')]
    #[Assert\Email(message: 'email.invalide')]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    /** Établissement ou entreprise (demandes des écoles et entreprises). */
    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $organization = null;

    /** Nombre de personnes à former, estimé. */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 100000, notInRangeMessage: 'contact.effectif.hors_limites')]
    private ?int $headcount = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'contact.message.vide')]
    #[Assert\Length(min: 10, max: 5000, minMessage: 'contact.message.trop_court')]
    private ?string $message = null;

    /** Le compte connecté au moment de l'envoi, s'il y en avait un. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /** Le certificat contesté (objet « Un litige sur un certificat »), envoyé depuis sa page. */
    #[ORM\ManyToOne(inversedBy: 'disputes')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Certificate $certificate = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(options: ['default' => false])]
    private bool $handled = false;

    public function __construct(?\DateTimeImmutable $now = null)
    {
        $this->createdAt = $now ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubject(): ?ContactSubject
    {
        return $this->subject;
    }

    public function setSubject(?ContactSubject $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = null === $name ? null : trim($name);

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = null === $email ? null : trim($email);

        return $this;
    }

    public function getOrganization(): ?string
    {
        return $this->organization;
    }

    public function setOrganization(?string $organization): static
    {
        $this->organization = null === $organization || '' === trim($organization) ? null : trim($organization);

        return $this;
    }

    public function getHeadcount(): ?int
    {
        return $this->headcount;
    }

    public function setHeadcount(?int $headcount): static
    {
        $this->headcount = $headcount;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = null === $message ? null : trim($message);

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
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

    public function getCertificate(): ?Certificate
    {
        return $this->certificate;
    }

    public function setCertificate(?Certificate $certificate): static
    {
        $this->certificate = $certificate;

        return $this;
    }
}
