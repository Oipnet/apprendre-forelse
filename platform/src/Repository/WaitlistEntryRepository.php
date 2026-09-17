<?php

namespace App\Repository;

use App\Entity\WaitlistEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WaitlistEntry>
 */
class WaitlistEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WaitlistEntry::class);
    }

    public function findOneByEmail(string $email): ?WaitlistEntry
    {
        return $this->findOneBy(['email' => WaitlistEntry::normalize($email)]);
    }
}
