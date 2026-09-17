<?php

namespace App\Entity;

use App\Repository\TrackAccessRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Accès d'un apprenant à un parcours, quelle que soit la façon dont il l'a obtenu (voir AccessSource).
 * Le parcours vit dans les packs : on ne le référence que par son identifiant, comme la progression.
 *
 * Un accès n'est jamais supprimé pour être retiré : il est révoqué (fin = maintenant), ce qui garde l'historique.
 * La progression n'en dépend pas : un accès expiré ou révoqué la laisse intacte, un nouvel accès la retrouve.
 */
#[ORM\Entity(repositoryClass: TrackAccessRepository::class)]
#[ORM\Index(name: 'IDX_TRACK_ACCESS_USER_TRACK', fields: ['user', 'trackId'])]
class TrackAccess
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** La cohorte qui a ouvert l'accès (source cohorte). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Cohort $cohort = null;

    /** L'achat qui a ouvert l'accès (source achat). Plusieurs accès par achat : prêt pour une offre groupant des parcours. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Purchase $purchase = null;

    /** Pourquoi, pour un accès offert (« bêta 2026 », « partenaire »…). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'accesses')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 100)]
        private string $trackId,
        #[ORM\Column(length: 20, enumType: AccessSource::class)]
        private AccessSource $source,
        #[ORM\Column]
        private \DateTimeImmutable $startsAt,
        /** null : à vie. */
        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $endsAt = null,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function gift(User $user, string $trackId, \DateTimeImmutable $now, ?\DateTimeImmutable $endsAt = null, ?string $note = null): self
    {
        $access = new self($user, $trackId, AccessSource::Gift, $now, $endsAt);
        $access->note = $note;

        return $access;
    }

    public static function purchased(Purchase $purchase, \DateTimeImmutable $now): self
    {
        $access = new self($purchase->getUser() ?? throw new \LogicException('Achat sans apprenant.'), $purchase->getTrackId(), AccessSource::Purchase, $now);
        $access->purchase = $purchase;

        return $access;
    }

    /** Aux dates de la cohorte. */
    public static function forCohort(User $user, string $trackId, Cohort $cohort): self
    {
        $access = new self($user, $trackId, AccessSource::Cohort, $cohort->getAccessStartsAt(), $cohort->getAccessEndsAt());
        $access->cohort = $cohort;

        return $access;
    }

    public function isActive(\DateTimeImmutable $now): bool
    {
        return $this->startsAt <= $now && (null === $this->endsAt || $this->endsAt > $now);
    }

    /** Termine l'accès maintenant ; sans effet s'il est déjà terminé. */
    public function revoke(\DateTimeImmutable $now): void
    {
        if (null === $this->endsAt || $this->endsAt > $now) {
            $this->endsAt = $now;
        }
    }

    /** Pour un accès de cohorte : suit les dates de la cohorte (et se rouvre si le parcours lui est rendu). */
    public function followCohortDates(Cohort $cohort): void
    {
        $this->startsAt = $cohort->getAccessStartsAt();
        $this->endsAt = $cohort->getAccessEndsAt();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTrackId(): string
    {
        return $this->trackId;
    }

    public function getSource(): AccessSource
    {
        return $this->source;
    }

    public function getCohort(): ?Cohort
    {
        return $this->cohort;
    }

    public function getPurchase(): ?Purchase
    {
        return $this->purchase;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(?\DateTimeImmutable $endsAt): static
    {
        $this->endsAt = $endsAt;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
