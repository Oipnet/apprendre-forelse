<?php

namespace App\Entity;

use App\Scratch\ScratchFramework;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un lien court du bac à sable (/bac-a-sable/s/<id>) : le code partagé, gardé sur le serveur au même format que le lien
 * long (le fragment « code=… », voir App\Scratch\ScratchLink). Créé seulement quand le visiteur garde la case « Lien
 * court » cochée, sans compte. Il expire à la date choisie au partage (ou jamais), et le navigateur qui l'a créé peut le supprimer
 * avant : il garde la clé de suppression, dont la base ne connaît que l'empreinte.
 */
#[ORM\Entity]
#[ORM\Table(name: 'scratch_share')]
#[ORM\Index(name: 'IDX_SCRATCH_SHARE_EXPIRES', fields: ['expiresAt'])]
class ScratchShare
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 16)]
        private string $id,
        #[ORM\Column(length: 16, enumType: ScratchFramework::class)]
        private ScratchFramework $framework,
        #[ORM\Column(type: 'text')]
        private string $code,
        #[ORM\Column(length: 64)]
        private string $deleteKeyHash,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $expiresAt,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getFramework(): ScratchFramework
    {
        return $this->framework;
    }

    /** Le fragment « code=… », tel que la page le lit après le #. */
    public function getCode(): string
    {
        return $this->code;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= $now;
    }

    public function opensWith(string $deleteKey): bool
    {
        return hash_equals($this->deleteKeyHash, hash('sha256', $deleteKey));
    }
}
