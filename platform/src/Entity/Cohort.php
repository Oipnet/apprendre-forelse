<?php

namespace App\Entity;

use App\Repository\CohortRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Groupe d'apprenants (classe, bêta, entreprise…) rejoint avec un code d'invitation à l'inscription.
 * Créée dans le tableau de bord d'administration.
 */
#[ORM\Entity(repositoryClass: CohortRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_COHORT_CODE', fields: ['code'])]
#[UniqueEntity(fields: ['code'], message: 'Ce code d\'invitation est déjà utilisé.')]
class Cohort implements \Stringable
{
    /** Longueur de la partie aléatoire du code ; la partie lisible a donc au plus 40 - 11 caractères. */
    public const int RANDOM_CODE_LENGTH = 10;
    public const int READABLE_CODE_MAX = 40 - 1 - self::RANDOM_CODE_LENGTH;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Nom lisible, ex. « BUT Info Annecy 2026 ». */
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Donnez un nom à la cohorte.')]
    #[Assert\Length(max: 80)]
    private ?string $name = null;

    /** Code saisi à l'inscription, ex. « iut-2026 » : minuscules, chiffres et tirets. */
    #[ORM\Column(length: 40)]
    #[Assert\NotBlank(message: 'Choisissez un code d\'invitation.')]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(-[a-z0-9]+)*$/', message: 'Minuscules, chiffres et tirets uniquement (ex. iut-2026).')]
    #[Assert\Length(max: 40)]
    private ?string $code = null;

    /** Une cohorte inactive n'accepte plus d'inscriptions (ses apprenants gardent leur accès). */
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, User> */
    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'cohort')]
    private Collection $users;

    /**
     * Parcours proposés aux apprenants de la cohorte, par identifiant (les parcours vivent dans les packs,
     * pas en base). Liste vide : aucune sélection, tous les parcours sont proposés, comme avant qu'elle existe.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $availableTrackIds = [];

    /**
     * Chefs de cohorte : enseignants qui suivent la cohorte et choisissent ses parcours (voir CohortVoter).
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'cohort_chef')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $chefs;

    /** Qui paie : l'établissement (accès ouverts à tous ses apprenants) ou chaque apprenant (achat). */
    #[ORM\Column(length: 20, enumType: FundingMode::class, options: ['default' => 'institution'])]
    private FundingMode $fundingMode = FundingMode::Institution;

    /** Début des accès ouverts par la cohorte (mode établissement). */
    #[ORM\Column]
    #[Assert\NotNull(message: 'Indiquez le début des accès.')]
    private \DateTimeImmutable $accessStartsAt;

    /** Fin des accès ouverts par la cohorte (exclue) ; la progression des apprenants reste. */
    #[ORM\Column]
    #[Assert\NotNull(message: 'Indiquez la fin des accès.')]
    private \DateTimeImmutable $accessEndsAt;

    /** Effectif prévu : base de l'estimation du devis, modifiable par le chef de cohorte. */
    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $expectedHeadcount = 0;

    /** Mode apprenants : prix par apprenant et par parcours (TTC, en centimes) ; null = prix courant du parcours. */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive(message: 'Un tarif de cohorte est payant : laissez vide pour le prix courant.')]
    private ?int $learnerPrice = null;

    /** Mode établissement : montant contractuel du devis (TTC, en centimes), saisi par l'administrateur. */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $quoteAmount = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->accessStartsAt = new \DateTimeImmutable('today');
        $this->accessEndsAt = $this->accessStartsAt->modify('+1 year');
        $this->users = new ArrayCollection();
        $this->chefs = new ArrayCollection();
    }

    #[Assert\Callback]
    public function validateFunding(ExecutionContextInterface $context): void
    {
        if ($this->accessEndsAt <= $this->accessStartsAt) {
            $context->buildViolation('La fin des accès doit suivre leur début.')->atPath('accessEndsAt')->addViolation();
        }
        if (FundingMode::Institution === $this->fundingMode && !$this->hasTrackSelection()) {
            // « Aucune sélection = tous les parcours » ouvrirait, sans devis, chaque parcours publié plus tard.
            $context->buildViolation('Une cohorte financée par l\'établissement doit choisir ses parcours.')->atPath('availableTrackIds')->addViolation();
        }
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = strtolower(trim($code));

        return $this;
    }

    /**
     * Ajoute au code une partie aléatoire (« iut-2026 » → « iut-2026-k3m9x7q2pw ») : un code d'invitation ouvre des
     * comptes, et des parcours payants pour une cohorte financée ; lisible seulement, il se devine.
     */
    public function addRandomCodePart(): static
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789'; // sans 0/o, 1/l/i : il se recopie à la main
        $part = '';
        for ($i = 0; $i < self::RANDOM_CODE_LENGTH; ++$i) {
            $part .= $alphabet[random_int(0, \strlen($alphabet) - 1)];
        }

        return $this->setCode($this->code.'-'.$part);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

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

    /** @return Collection<int, User> */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    /** @return list<string> */
    public function getAvailableTrackIds(): array
    {
        return $this->availableTrackIds;
    }

    /** @param iterable<string> $trackIds */
    public function setAvailableTrackIds(iterable $trackIds): static
    {
        $this->availableTrackIds = array_values(array_unique([...$trackIds]));

        return $this;
    }

    /** Des parcours ont été choisis : seuls ceux-là sont proposés. */
    public function hasTrackSelection(): bool
    {
        return [] !== $this->availableTrackIds;
    }

    /** Le parcours est proposé aux apprenants : choisi, ou aucune sélection n'a encore été faite. */
    public function offersTrack(string $trackId): bool
    {
        return !$this->hasTrackSelection() || $this->selectsTrack($trackId);
    }

    /** Le parcours a été explicitement choisi (seul moyen d'ouvrir un parcours en préparation). */
    public function selectsTrack(string $trackId): bool
    {
        return \in_array($trackId, $this->availableTrackIds, true);
    }

    /** @return Collection<int, User> */
    public function getChefs(): Collection
    {
        return $this->chefs;
    }

    public function addChef(User $chef): static
    {
        if (!$this->chefs->contains($chef)) {
            $this->chefs->add($chef);
        }

        return $this;
    }

    public function removeChef(User $chef): static
    {
        $this->chefs->removeElement($chef);

        return $this;
    }

    public function isChef(User $user): bool
    {
        return null !== $user->getId() && $this->chefs->exists(static fn (int $key, User $chef) => $chef->getId() === $user->getId());
    }

    public function getFundingMode(): FundingMode
    {
        return $this->fundingMode;
    }

    public function setFundingMode(FundingMode $fundingMode): static
    {
        $this->fundingMode = $fundingMode;

        return $this;
    }

    public function isFundedByInstitution(): bool
    {
        return FundingMode::Institution === $this->fundingMode;
    }

    public function getAccessStartsAt(): \DateTimeImmutable
    {
        return $this->accessStartsAt;
    }

    public function setAccessStartsAt(?\DateTimeImmutable $accessStartsAt): static
    {
        $this->accessStartsAt = $accessStartsAt ?? $this->accessStartsAt;

        return $this;
    }

    public function getAccessEndsAt(): \DateTimeImmutable
    {
        return $this->accessEndsAt;
    }

    public function setAccessEndsAt(?\DateTimeImmutable $accessEndsAt): static
    {
        $this->accessEndsAt = $accessEndsAt ?? $this->accessEndsAt;

        return $this;
    }

    public function getExpectedHeadcount(): int
    {
        return $this->expectedHeadcount;
    }

    public function setExpectedHeadcount(?int $expectedHeadcount): static
    {
        $this->expectedHeadcount = max(0, (int) $expectedHeadcount);

        return $this;
    }

    public function getLearnerPrice(): ?int
    {
        return $this->learnerPrice;
    }

    public function setLearnerPrice(?int $learnerPrice): static
    {
        $this->learnerPrice = $learnerPrice;

        return $this;
    }

    public function getQuoteAmount(): ?int
    {
        return $this->quoteAmount;
    }

    public function setQuoteAmount(?int $quoteAmount): static
    {
        $this->quoteAmount = $quoteAmount;

        return $this;
    }
}
