<?php

namespace App\Repository;

use App\Entity\TrackPricing;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Les tarifs sont lus d'un bloc, une fois par requête : la table tient en quelques lignes, et l'accueil ou la page
 * d'un parcours en demandent un par parcours et par chapitre (findOneBy ne passe pas par la carte d'identité de
 * Doctrine, chaque appel était une requête). Un tarif modifié reste le même objet, donc à jour ; un tarif créé ou
 * supprimé se voit à la requête suivante, ou après reset().
 *
 * @extends ServiceEntityRepository<TrackPricing>
 */
class TrackPricingRepository extends ServiceEntityRepository implements ResetInterface
{
    /** @var array<string, TrackPricing>|null */
    private ?array $byTrack = null;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackPricing::class);
    }

    public function findOneByTrack(string $trackId): ?TrackPricing
    {
        if (null === $this->byTrack) {
            $this->byTrack = [];
            foreach ($this->findAll() as $pricing) {
                $this->byTrack[(string) $pricing->getTrackId()] = $pricing;
            }
        }

        return $this->byTrack[$trackId] ?? null;
    }

    public function reset(): void
    {
        $this->byTrack = null;
    }

    /** Un parcours sans tarif, ou à 0 €, est gratuit. */
    public function isPaid(string $trackId): bool
    {
        return !($this->findOneByTrack($trackId)?->isFree() ?? true);
    }
}
