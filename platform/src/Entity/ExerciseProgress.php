<?php

namespace App\Entity;

use App\Repository\ExerciseProgressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Progression d'un apprenant sur un exercice. Le contenu vit dans les packs (fichiers) :
 * on ne référence les exercices que par leurs identifiants. Un exercice de Pratique n'a pas de parcours
 * (trackId null) : deux index partiels gardent une seule progression par exercice dans les deux cas.
 */
#[ORM\Entity(repositoryClass: ExerciseProgressRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_PROGRESS_EXERCISE', fields: ['user', 'trackId', 'exerciseId'], options: ['where' => '(track_id IS NOT NULL)'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PROGRESS_PRACTICE', fields: ['user', 'exerciseId'], options: ['where' => '(track_id IS NULL)'])]
class ExerciseProgress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: ProgressStatus::class)]
    private ProgressStatus $status = ProgressStatus::InProgress;

    /** @var array<string, string> brouillons des fichiers éditables */
    #[ORM\Column(type: Types::JSON)]
    private array $files = [];

    #[ORM\Column(options: ['default' => 0])]
    private int $hintsUsed = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $xpEarned = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** La solution a été consultée : l'exercice ne rapporte plus d'XP (voir ProgressService::complete). */
    #[ORM\Column(options: ['default' => false])]
    private bool $solutionRevealed = false;

    /** @var array<string, mixed>|null revue de code du mentor, conservée pour être relue */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $review = null;

    /** Premier brouillon sauvegardé : avec completedAt, donne une durée approximative. */
    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'progress')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 100, nullable: true)]
        private ?string $trackId,
        #[ORM\Column(length: 100)]
        private string $exerciseId,
    ) {
        $this->startedAt = $this->updatedAt = new \DateTimeImmutable();
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

    public function getStatus(): ProgressStatus
    {
        return $this->status;
    }

    public function isCompleted(): bool
    {
        return ProgressStatus::Completed === $this->status;
    }

    /** @return array<string, string> */
    public function getFiles(): array
    {
        return $this->files;
    }

    public function getHintsUsed(): int
    {
        return $this->hintsUsed;
    }

    public function getXpEarned(): int
    {
        return $this->xpEarned;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isSolutionRevealed(): bool
    {
        return $this->solutionRevealed;
    }

    public function revealSolution(): void
    {
        $this->solutionRevealed = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** @return array<string, mixed>|null */
    public function getReview(): ?array
    {
        return $this->review;
    }

    /** @param array<string, mixed> $review */
    public function setReview(array $review): void
    {
        $this->review = $review;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @param array<string, string> $files
     */
    public function saveDraft(array $files, int $hintsUsed): void
    {
        $this->files = $files;
        // Un indice consulté ne se « rend » pas.
        $this->hintsUsed = max($this->hintsUsed, $hintsUsed);
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Marque l'exercice réussi. Renvoie l'XP gagnée : 0 s'il l'était déjà.
     */
    public function complete(int $hintsUsed, int $xp): int
    {
        if ($this->isCompleted()) {
            return 0;
        }
        $this->hintsUsed = max($this->hintsUsed, $hintsUsed);
        $this->status = ProgressStatus::Completed;
        $this->xpEarned = $xp;
        $this->completedAt = $this->updatedAt = new \DateTimeImmutable();

        return $xp;
    }
}
