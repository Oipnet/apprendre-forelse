<?php

namespace App\Entity;

use App\Repository\TrackBonusRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le bonus d'un parcours terminé (voir App\Leaderboard\TrackBonuses) : une fois par apprenant et par parcours, ajouté
 * à son XP et compté au classement du mois où il tombe. Retiré si une réussite du parcours est infirmée par le
 * verificateur. Supprimé avec le compte.
 */
#[ORM\Entity(repositoryClass: TrackBonusRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_TRACK_BONUS', fields: ['user', 'trackId'])]
class TrackBonus
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 100)]
        private string $trackId,
        #[ORM\Column]
        private int $xp,
        #[ORM\Column]
        private \DateTimeImmutable $awardedAt,
    ) {
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

    public function getXp(): int
    {
        return $this->xp;
    }

    public function getAwardedAt(): \DateTimeImmutable
    {
        return $this->awardedAt;
    }
}
