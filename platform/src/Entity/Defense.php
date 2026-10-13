<?php

namespace App\Entity;

use App\Repository\DefenseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un créneau de soutenance de fin de parcours, ouvert par un chef de cohorte : libre, puis réservé par un apprenant
 * qui a terminé le parcours, puis évalué (grille par chapitre, commentaire, résultat). Une soutenance à repasser se
 * repasse sur un autre créneau : chaque passage garde le sien.
 */
#[ORM\Entity(repositoryClass: DefenseRepository::class)]
#[ORM\Index(name: 'IDX_DEFENSE_COHORT_STARTS', fields: ['cohort', 'startsAt'])]
class Defense
{
    public const int MIN_DURATION = 10;
    public const int MAX_DURATION = 240;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** L'apprenant qui a réservé le créneau ; null tant qu'il est libre (ou si le compte a été supprimé). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $learner = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $bookedAt = null;

    #[ORM\Column(length: 20, nullable: true, enumType: DefenseOutcome::class)]
    private ?DefenseOutcome $outcome = null;

    /**
     * La grille : le niveau atteint par chapitre du parcours, par identifiant de chapitre.
     *
     * @var array<string, string> valeurs de DefenseLevel
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $grades = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $evaluatedBy = null;

    /** Le nom de l'évaluateur au moment de l'évaluation : il reste dans l'export si son compte disparaît. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $evaluatorName = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $evaluatedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Cohort $cohort,
        #[ORM\Column(length: 100)]
        private string $trackId,
        #[ORM\Column]
        private \DateTimeImmutable $startsAt,
        #[ORM\Column]
        private int $durationMinutes,
        #[ORM\Column(length: 20, enumType: DefenseMode::class)]
        private DefenseMode $mode,
        /** Le lien de la visio, ou l'adresse et la salle. */
        #[ORM\Column(length: 255)]
        private string $place,
        \DateTimeImmutable $now,
    ) {
        $this->createdAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCohort(): Cohort
    {
        return $this->cohort;
    }

    public function getTrackId(): string
    {
        return $this->trackId;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->startsAt->modify(sprintf('+%d minutes', $this->durationMinutes));
    }

    public function getDurationMinutes(): int
    {
        return $this->durationMinutes;
    }

    public function getMode(): DefenseMode
    {
        return $this->mode;
    }

    public function getPlace(): string
    {
        return $this->place;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLearner(): ?User
    {
        return $this->learner;
    }

    public function getBookedAt(): ?\DateTimeImmutable
    {
        return $this->bookedAt;
    }

    public function isBooked(): bool
    {
        return null !== $this->bookedAt;
    }

    public function isEvaluated(): bool
    {
        return null !== $this->outcome;
    }

    public function hasStarted(\DateTimeImmutable $now): bool
    {
        return $this->startsAt <= $now;
    }

    public function book(User $learner, \DateTimeImmutable $now): void
    {
        $this->learner = $learner;
        $this->bookedAt = $now;
    }

    /** Le créneau redevient libre. */
    public function release(): void
    {
        $this->learner = null;
        $this->bookedAt = null;
    }

    public function getOutcome(): ?DefenseOutcome
    {
        return $this->outcome;
    }

    /** @return array<string, DefenseLevel> par identifiant de chapitre */
    public function getGrades(): array
    {
        return array_map(DefenseLevel::from(...), $this->grades);
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getEvaluatedBy(): ?User
    {
        return $this->evaluatedBy;
    }

    public function getEvaluatorName(): ?string
    {
        return $this->evaluatorName;
    }

    public function getEvaluatedAt(): ?\DateTimeImmutable
    {
        return $this->evaluatedAt;
    }

    /** @param array<string, DefenseLevel> $grades par identifiant de chapitre */
    public function evaluate(User $evaluator, array $grades, DefenseOutcome $outcome, ?string $comment, \DateTimeImmutable $now): void
    {
        $this->grades = array_map(static fn (DefenseLevel $level) => $level->value, $grades);
        $this->outcome = $outcome;
        $this->comment = null === $comment || '' === trim($comment) ? null : trim($comment);
        $this->evaluatedBy = $evaluator;
        $this->evaluatorName = (string) $evaluator->getDisplayName();
        $this->evaluatedAt = $now;
    }
}
