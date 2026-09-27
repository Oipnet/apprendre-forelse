<?php

namespace App\EventListener;

use App\Entity\Touchable;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Clock\ClockInterface;

/**
 * Date la dernière modification d'une entité Touchable, quel que soit le chemin d'écriture (formulaire
 * d'administration, commande) : les setters n'ont pas à connaître l'heure, et l'horloge se fige en test.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final readonly class TouchListener
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $manager = $args->getObjectManager();
        $unitOfWork = $manager->getUnitOfWork();
        foreach ([...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates()] as $entity) {
            if ($entity instanceof Touchable) {
                $entity->touch($this->clock->now());
                $unitOfWork->recomputeSingleEntityChangeSet($manager->getClassMetadata($entity::class), $entity);
            }
        }
    }
}
