<?php

namespace App\Repository;

use App\Entity\ExternalIdentity;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExternalIdentity>
 */
class ExternalIdentityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalIdentity::class);
    }

    public function findOneByProviderId(string $provider, string $providerUserId): ?ExternalIdentity
    {
        return $this->findOneBy(['provider' => $provider, 'providerUserId' => $providerUserId]);
    }

    public function findOneByUser(User $user, string $provider): ?ExternalIdentity
    {
        return $this->findOneBy(['user' => $user, 'provider' => $provider]);
    }

    /** @return array<string, ExternalIdentity> les comptes liés, par fournisseur */
    public function findByUser(User $user): array
    {
        $byProvider = [];
        foreach ($this->findBy(['user' => $user], ['linkedAt' => 'ASC']) as $identity) {
            $byProvider[$identity->getProvider()] = $identity;
        }

        return $byProvider;
    }
}
