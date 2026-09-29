<?php

namespace App\Account;

use App\Entity\WaitlistEntry;
use App\Repository\WaitlistEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** La liste d'attente de la page d'accueil : une adresse, inscrite une seule fois. */
final readonly class Waitlist
{
    public function __construct(
        private WaitlistEntryRepository $entries,
        private EntityManagerInterface $entityManager,
        private ValidatorInterface $validator,
        private ClockInterface $clock,
    ) {
    }

    /** Faux pour une adresse invalide ; une adresse déjà inscrite ne l'est pas deux fois. */
    public function join(string $email): bool
    {
        $email = trim($email);
        if (\count($this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 180)])) > 0) {
            return false;
        }

        if (null === $this->entries->findOneByEmail($email)) {
            $this->entityManager->persist(new WaitlistEntry($email, $this->clock->now()));
            $this->entityManager->flush();
        }

        return true;
    }
}
