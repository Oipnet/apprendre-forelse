<?php

namespace App\Repository;

use App\Entity\Certificate;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Certificate> */
class CertificateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Certificate::class);
    }

    public function findByCode(string $code): ?Certificate
    {
        return $this->findOneBy(['code' => strtoupper($code)]);
    }

    /** Le certificat en vigueur (non révoqué) de l'apprenant pour ce parcours. */
    public function findValid(User $user, string $trackId): ?Certificate
    {
        return $this->findOneBy(['user' => $user, 'trackId' => $trackId, 'revokedAt' => null], ['issuedAt' => 'DESC']);
    }

    /** Le dernier certificat de l'apprenant pour ce parcours, révoqué ou non. */
    public function findLatest(User $user, string $trackId): ?Certificate
    {
        return $this->findOneBy(['user' => $user, 'trackId' => $trackId], ['issuedAt' => 'DESC']);
    }
}
