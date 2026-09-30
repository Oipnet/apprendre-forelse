<?php

namespace App\Entity;

use App\Repository\InstanceSettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un réglage de l'instance choisi depuis l'admin, et non par une variable d'environnement : il change sans
 * redémarrer le conteneur. Aujourd'hui le seul est le thème actif (voir ActiveTheme). On garde qui l'a changé, et quand.
 */
#[ORM\Entity(repositoryClass: InstanceSettingRepository::class)]
class InstanceSetting
{
    #[ORM\Column(type: Types::TEXT)]
    private string $value;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** L'adresse du compte qui l'a changé en dernier ; null pour une commande. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $updatedBy;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 64)]
        private string $name,
        string $value,
        \DateTimeImmutable $now,
        ?string $by = null,
    ) {
        $this->value = $value;
        $this->updatedAt = $now;
        $this->updatedBy = $by;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?string
    {
        return $this->updatedBy;
    }

    public function change(string $value, \DateTimeImmutable $now, ?string $by): void
    {
        $this->value = $value;
        $this->updatedAt = $now;
        $this->updatedBy = $by;
    }
}
