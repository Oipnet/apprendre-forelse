<?php

namespace App\Repository;

use App\Entity\TrackSeo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Le référencement saisi dans l'admin est lu d'un bloc, une fois par requête, comme les tarifs (TrackPricingRepository) :
 * la table tient en quelques lignes, et la page d'un parcours la demandait pour le title puis pour la description
 * (findOneBy ne passe pas par la carte d'identité de Doctrine, chaque appel était une requête). Une saisie modifiée
 * reste le même objet, donc à jour ; une saisie créée ou supprimée se voit à la requête suivante, ou après reset().
 *
 * @extends ServiceEntityRepository<TrackSeo>
 */
class TrackSeoRepository extends ServiceEntityRepository implements ResetInterface
{
    /** @var array<string, TrackSeo>|null */
    private ?array $byTrack = null;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackSeo::class);
    }

    public function findOneByTrack(string $trackId): ?TrackSeo
    {
        if (null === $this->byTrack) {
            $this->byTrack = [];
            foreach ($this->findAll() as $seo) {
                $this->byTrack[(string) $seo->getTrackId()] = $seo;
            }
        }

        return $this->byTrack[$trackId] ?? null;
    }

    public function reset(): void
    {
        $this->byTrack = null;
    }
}
