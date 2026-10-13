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

    /**
     * Le code au moment de la réussite, figé : le brouillon, lui, continue de changer. C'est lui que le service
     * « verificateur » rejoue (voir App\Verification).
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $submittedFiles = null;

    /** Vérification serveur de la réussite ; null tant que l'exercice n'est pas réussi. */
    #[ORM\Column(length: 20, nullable: true, enumType: VerificationStatus::class)]
    private ?VerificationStatus $verification = null;

    /** Dernier envoi au verificateur : une demande perdue (service arrêté) repart au bout d'un jour. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verificationRequestedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    /** Pourquoi la vérification a échoué ou n'a pas pu se faire (pour l'admin). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $verificationDetail = null;

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

    public function revealSolution(\DateTimeImmutable $now): void
    {
        $this->solutionRevealed = true;
        $this->updatedAt = $now;
    }

    /** @return array<string, mixed>|null */
    public function getReview(): ?array
    {
        return $this->review;
    }

    /** @param array<string, mixed> $review */
    public function setReview(array $review, \DateTimeImmutable $now): void
    {
        $this->review = $review;
        $this->updatedAt = $now;
    }

    /**
     * @param array<string, string> $files
     */
    public function saveDraft(array $files, int $hintsUsed, \DateTimeImmutable $now): void
    {
        $this->files = $files;
        // Un indice consulté ne se « rend » pas.
        $this->hintsUsed = max($this->hintsUsed, $hintsUsed);
        $this->updatedAt = $now;
    }

    /**
     * Marque l'exercice réussi. Renvoie l'XP gagnée : 0 s'il l'était déjà.
     */
    public function complete(int $hintsUsed, int $xp, \DateTimeImmutable $now): int
    {
        if ($this->isCompleted()) {
            return 0;
        }
        $this->hintsUsed = max($this->hintsUsed, $hintsUsed);
        $this->status = ProgressStatus::Completed;
        $this->xpEarned = $xp;
        $this->completedAt = $this->updatedAt = $now;
        // Le navigateur enregistre le brouillon juste avant d'annoncer la réussite (voir session.ts) : c'est le code qui a réussi.
        // Sans brouillon, la réussite se vérifie quand même, sur le code de départ : une réussite annoncée sans code
        // ne passe plus pour « non vérifiable », qui ouvrait le certificat et le classement sans rien résoudre.
        $this->submittedFiles = $this->files;
        $this->verification = VerificationStatus::Pending;

        return $xp;
    }

    /** @return array<string, string>|null */
    public function getSubmittedFiles(): ?array
    {
        return $this->submittedFiles;
    }

    public function getVerification(): ?VerificationStatus
    {
        return $this->verification;
    }

    public function getVerificationRequestedAt(): ?\DateTimeImmutable
    {
        return $this->verificationRequestedAt;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function getVerificationDetail(): ?string
    {
        return $this->verificationDetail;
    }

    public function markVerificationRequested(\DateTimeImmutable $now): void
    {
        $this->verificationRequestedAt = $now;
    }

    public function recordVerification(VerificationStatus $status, ?string $detail, \DateTimeImmutable $now): void
    {
        $this->verification = $status;
        $this->verificationDetail = null === $detail ? null : mb_substr($detail, 0, 255);
        $this->verifiedAt = $now;
    }
}
