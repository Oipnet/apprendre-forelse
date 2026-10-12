<?php

namespace App\Entity;

use App\Repository\CertificateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le certificat de réussite d'un parcours, délivré une fois tous ses exercices réussis (voir CertificateIssuer).
 *
 * Figé à la délivrance : titre du parcours, nom du titulaire et détail de chaque exercice (indices, solution consultée)
 * ne bougent plus, même si le parcours change ensuite. Seule la révocation le modifie ; chaque décision est gardée
 * dans l'historique. Vérifiable par tous à son adresse publique (/certificat/{code}).
 *
 * Supprimé avec le compte (RGPD) : la clé étrangère s'en charge.
 */
#[ORM\Entity(repositoryClass: CertificateRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_CERTIFICATE_CODE', fields: ['code'])]
#[ORM\Index(name: 'IDX_CERTIFICATE_TRACK', fields: ['user', 'trackId'])]
class Certificate implements \Stringable
{
    public const string ACTION_ISSUED = 'delivre';
    public const string ACTION_REVOKED = 'revoque';
    public const string ACTION_REINSTATED = 'retabli';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $revocationReason = null;

    /** @var list<array{at: string, action: string, note: string|null}> */
    #[ORM\Column(type: Types::JSON)]
    private array $history = [];

    /**
     * Ce qui s'ajoute au certificat après sa délivrance, affiché sur la page et le PDF : « Soutenance validée le … ».
     *
     * @var list<array{at: string, label: string}>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $mentions = [];

    /**
     * Les messages de contestation envoyés depuis la page du certificat.
     *
     * @var Collection<int, ContactMessage>
     */
    #[ORM\OneToMany(targetEntity: ContactMessage::class, mappedBy: 'certificate')]
    private Collection $disputes;

    /**
     * @param list<array{chapter: string, id: string, title: string, hintsUsed: int, hintsAvailable: int, solutionRevealed: bool, xp: int}> $exercises
     */
    public function __construct(
        /** Public, à recopier : 8 caractères sans ambiguïté (voir CertificateCode). */
        #[ORM\Column(length: 9)]
        private string $code,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 100)]
        private string $trackId,
        #[ORM\Column(length: 200)]
        private string $trackTitle,
        /** Le nom confirmé par l'apprenant au moment de la délivrance. */
        #[ORM\Column(length: 80)]
        private string $holderName,
        /** Le détail de chaque exercice du parcours, dans l'ordre. */
        #[ORM\Column(type: Types::JSON)]
        private array $exercises,
        #[ORM\Column]
        private \DateTimeImmutable $issuedAt,
    ) {
        $this->disputes = new ArrayCollection();
        $this->record(self::ACTION_ISSUED, null, $issuedAt);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTrackId(): string
    {
        return $this->trackId;
    }

    public function getTrackTitle(): string
    {
        return $this->trackTitle;
    }

    public function getHolderName(): string
    {
        return $this->holderName;
    }

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    /** @return list<array{chapter: string, id: string, title: string, hintsUsed: int, hintsAvailable: int, solutionRevealed: bool, xp: int}> */
    public function getExercises(): array
    {
        return $this->exercises;
    }

    /**
     * Le détail regroupé par chapitre, dans l'ordre du parcours.
     *
     * @return array<string, list<array{chapter: string, id: string, title: string, hintsUsed: int, hintsAvailable: int, solutionRevealed: bool, xp: int}>>
     */
    public function getExercisesByChapter(): array
    {
        $chapters = [];
        foreach ($this->exercises as $exercise) {
            $chapters[$exercise['chapter']][] = $exercise;
        }

        return $chapters;
    }

    public function getExerciseCount(): int
    {
        return \count($this->exercises);
    }

    /** Exercices réussis sans consulter la solution. */
    public function getWithoutSolutionCount(): int
    {
        return \count(array_filter($this->exercises, static fn (array $e) => !$e['solutionRevealed']));
    }

    /** Exercices réussis sans indice ni solution. */
    public function getUnaidedCount(): int
    {
        return \count(array_filter($this->exercises, static fn (array $e) => !$e['solutionRevealed'] && 0 === $e['hintsUsed']));
    }

    public function getXp(): int
    {
        return array_sum(array_column($this->exercises, 'xp'));
    }

    public function hasExercise(string $exerciseId): bool
    {
        return \in_array($exerciseId, array_column($this->exercises, 'id'), true);
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getRevocationReason(): ?string
    {
        return $this->revocationReason;
    }

    /** Une contestation reçue depuis la révocation, pas encore traitée. */
    public function isDisputed(): bool
    {
        return $this->isRevoked() && $this->disputes->exists(fn (int $key, ContactMessage $message) => !$message->isHandled() && $message->getCreatedAt() >= $this->revokedAt);
    }

    /** @return Collection<int, ContactMessage> */
    public function getDisputes(): Collection
    {
        return $this->disputes;
    }

    /** @return list<array{at: string, action: string, note: string|null}> */
    public function getHistory(): array
    {
        return $this->history;
    }

    /** @return list<array{at: string, label: string}> */
    public function getMentions(): array
    {
        return $this->mentions;
    }

    public function addMention(string $label, \DateTimeImmutable $at): void
    {
        $this->mentions[] = ['at' => $at->format(\DATE_ATOM), 'label' => $label];
    }

    public function revoke(string $reason, \DateTimeImmutable $now): void
    {
        $this->revokedAt = $now;
        $this->revocationReason = $reason;
        $this->record(self::ACTION_REVOKED, $reason, $now);
    }

    public function reinstate(?string $note, \DateTimeImmutable $now): void
    {
        $this->revokedAt = null;
        $this->revocationReason = null;
        $this->record(self::ACTION_REINSTATED, $note, $now);
    }

    public function __toString(): string
    {
        return sprintf('%s · %s', $this->code, $this->trackTitle);
    }

    private function record(string $action, ?string $note, \DateTimeImmutable $at): void
    {
        $this->history[] = ['at' => $at->format(\DATE_ATOM), 'action' => $action, 'note' => $note];
    }
}
