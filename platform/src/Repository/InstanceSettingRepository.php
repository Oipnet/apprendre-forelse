<?php

namespace App\Repository;

use App\Entity\InstanceSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InstanceSetting>
 */
class InstanceSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstanceSetting::class);
    }

    public function setting(string $name): ?InstanceSetting
    {
        return $this->find($name);
    }

    /** Enregistre la valeur, et qui l'a choisie. */
    public function change(string $name, string $value, \DateTimeImmutable $now, ?string $by = null): InstanceSetting
    {
        $setting = $this->find($name);
        if (null === $setting) {
            $setting = new InstanceSetting($name, $value, $now, $by);
            $this->getEntityManager()->persist($setting);
        } else {
            $setting->change($value, $now, $by);
        }
        $this->getEntityManager()->flush();

        return $setting;
    }
}
