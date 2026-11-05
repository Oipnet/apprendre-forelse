<?php

namespace App\Scratch;

use App\Entity\ScratchShare;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Les liens courts du bac à sable : le fragment « code=… » du lien long, gardé sous un identifiant de 8 caractères
 * tiré au hasard (62^8 possibilités : on ne devine pas le lien d'un autre). Seul un fragment que la page saurait lire
 * est accepté (ScratchLink, mêmes plafonds) : la base ne garde rien d'autre que du code partageable.
 */
final readonly class ScratchShortLinks
{
    public const int ID_LENGTH = 8;
    private const string ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ScratchLink $links,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Garde ce code et renvoie le lien créé, avec la clé qui permettra de le supprimer (à ne donner qu'au créateur).
     *
     * @throws \InvalidArgumentException si la page ne saurait pas lire ce fragment
     */
    public function create(ScratchFramework $framework, string $fragment, ScratchShareDuration $duration): CreatedScratchShare
    {
        if (null === $this->links->decode($fragment)) {
            throw new \InvalidArgumentException('This is not a playground code.');
        }
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $deleteKey = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        // Un identifiant déjà pris (une chance sur des milliards) : on en tire un autre.
        do {
            $id = self::randomId();
        } while (null !== $this->entityManager->find(ScratchShare::class, $id));
        $share = new ScratchShare($id, $framework, $fragment, hash('sha256', $deleteKey), $now, $duration->expiresAt($now));
        $this->entityManager->persist($share);
        $this->entityManager->flush();

        return new CreatedScratchShare($share, $deleteKey);
    }

    /** Le lien court encore valable ; null s'il n'existe pas, a expiré ou a été supprimé. */
    public function find(string $id): ?ScratchShare
    {
        $share = $this->entityManager->find(ScratchShare::class, $id);

        return null === $share || $share->isExpired(\DateTimeImmutable::createFromInterface($this->clock->now())) ? null : $share;
    }

    /** Supprime le lien si la clé est la sienne ; faux sinon (lien inconnu ou mauvaise clé, sans dire lequel). */
    public function delete(string $id, string $deleteKey): bool
    {
        $share = $this->entityManager->find(ScratchShare::class, $id);
        if (null === $share || !$share->opensWith($deleteKey)) {
            return false;
        }
        $this->entityManager->remove($share);
        $this->entityManager->flush();

        return true;
    }

    /** Efface les liens expirés (planificateur, voir ScratchSchedule) ; renvoie combien. */
    public function purge(): int
    {
        return (int) $this->entityManager->createQuery('DELETE FROM '.ScratchShare::class.' s WHERE s.expiresAt <= :now')
            ->setParameter('now', \DateTimeImmutable::createFromInterface($this->clock->now()))
            ->execute();
    }

    private static function randomId(): string
    {
        $id = '';
        for ($i = 0; $i < self::ID_LENGTH; ++$i) {
            $id .= self::ALPHABET[random_int(0, 61)];
        }

        return $id;
    }
}
