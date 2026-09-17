<?php

namespace App\Entity;

use App\Repository\TrackPricingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Prix d'un parcours sur cette instance. Les prix appartiennent à l'instance, pas au pack : ils vivent en base,
 * pas dans track.yaml. Un parcours sans tarif, ou à 0 €, est gratuit : tout compte y accède.
 *
 * Montants en centimes, TTC (la TVA est calculée par Stripe Tax, prix TTC inclus).
 *
 * Plus tard, une offre groupée (« Offre » : plusieurs parcours pour un prix) aura sa propre entité ; ce tarif
 * restera celui du parcours acheté seul, et un achat pourra ouvrir plusieurs accès (TrackAccess::$purchase).
 */
#[ORM\Entity(repositoryClass: TrackPricingRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_TRACK_PRICING_TRACK', fields: ['trackId'])]
#[UniqueEntity(fields: ['trackId'], message: 'Ce parcours a déjà un tarif.')]
class TrackPricing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private ?string $trackId = null;

    /** Prix normal, en centimes. 0 : parcours gratuit, pas de bouton d'achat. */
    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $normalPrice = 0;

    /** Prix fondateur, en centimes : appliqué tant qu'il est actif, que sa date et son quota ne sont pas dépassés. */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $founderPrice = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $founderActive = false;

    /** Fin du prix fondateur (exclue) ; null : pas de date limite. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $founderEndsAt = null;

    /** Nombre d'achats au prix fondateur au-delà duquel il cesse ; null : pas de quota. */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?int $founderQuotaMax = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(?string $trackId = null)
    {
        $this->trackId = $trackId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isFree(): bool
    {
        return $this->normalPrice <= 0;
    }

    /**
     * Le prix fondateur s'applique-t-il ?
     *
     * @param int $founderSales achats déjà payés au prix fondateur
     */
    public function isFounderPriceApplicable(\DateTimeImmutable $now, int $founderSales): bool
    {
        return !$this->isFree()
            && $this->founderActive
            && null !== $this->founderPrice
            && (null === $this->founderEndsAt || $now < $this->founderEndsAt)
            && (null === $this->founderQuotaMax || $founderSales < $this->founderQuotaMax);
    }

    /** Prix affiché et facturé maintenant, en centimes. */
    public function currentPrice(\DateTimeImmutable $now, int $founderSales): int
    {
        return $this->isFounderPriceApplicable($now, $founderSales) ? (int) $this->founderPrice : $this->normalPrice;
    }

    /** Places restantes au prix fondateur ; null sans quota. */
    public function founderSeatsLeft(int $founderSales): ?int
    {
        return null === $this->founderQuotaMax ? null : max(0, $this->founderQuotaMax - $founderSales);
    }

    #[Assert\Callback]
    public function validateFounderPrice(ExecutionContextInterface $context): void
    {
        if (null !== $this->founderPrice && $this->founderPrice >= $this->normalPrice) {
            $context->buildViolation('Le prix fondateur doit être inférieur au prix normal.')->atPath('founderPrice')->addViolation();
        }
        if ($this->founderActive && null === $this->founderPrice) {
            $context->buildViolation('Indiquez le prix fondateur, ou désactivez-le.')->atPath('founderPrice')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrackId(): ?string
    {
        return $this->trackId;
    }

    public function setTrackId(string $trackId): static
    {
        $this->trackId = $trackId;

        return $this;
    }

    public function getNormalPrice(): int
    {
        return $this->normalPrice;
    }

    public function setNormalPrice(?int $normalPrice): static
    {
        $this->normalPrice = (int) $normalPrice;
        $this->touch();

        return $this;
    }

    public function getFounderPrice(): ?int
    {
        return $this->founderPrice;
    }

    public function setFounderPrice(?int $founderPrice): static
    {
        $this->founderPrice = $founderPrice;
        $this->touch();

        return $this;
    }

    public function isFounderActive(): bool
    {
        return $this->founderActive;
    }

    public function setFounderActive(bool $founderActive): static
    {
        $this->founderActive = $founderActive;
        $this->touch();

        return $this;
    }

    public function getFounderEndsAt(): ?\DateTimeImmutable
    {
        return $this->founderEndsAt;
    }

    public function setFounderEndsAt(?\DateTimeImmutable $founderEndsAt): static
    {
        $this->founderEndsAt = $founderEndsAt;
        $this->touch();

        return $this;
    }

    public function getFounderQuotaMax(): ?int
    {
        return $this->founderQuotaMax;
    }

    public function setFounderQuotaMax(?int $founderQuotaMax): static
    {
        $this->founderQuotaMax = $founderQuotaMax;
        $this->touch();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
