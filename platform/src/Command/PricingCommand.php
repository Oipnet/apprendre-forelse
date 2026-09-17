<?php

namespace App\Command;

use App\Content\ContentRepository;
use App\Entity\TrackPricing;
use App\Repository\TrackPricingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Fixe le tarif d'un parcours depuis la console (même effet que /admin → Tarifs). Montants en euros TTC.
 * Exemple : app:tarif symfony-pour-dev-php 79 --fondateur=49 --quota=100
 */
#[AsCommand(name: 'app:tarif', description: 'Fixe le prix normal et le prix fondateur d\'un parcours (euros TTC).')]
final readonly class PricingCommand
{
    public function __construct(
        private ContentRepository $content,
        private TrackPricingRepository $pricings,
        private EntityManagerInterface $entityManager,
        private ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Identifiant du parcours (track.yaml)')] string $parcours,
        #[Argument('Prix normal en euros TTC (0 : gratuit)')] string $prix,
        #[Option('Prix fondateur en euros TTC (l\'active)')] ?string $fondateur = null,
        #[Option('Nombre d\'achats au prix fondateur avant qu\'il cesse')] ?int $quota = null,
        #[Option('Fin du prix fondateur (AAAA-MM-JJ, exclue)')] ?string $fin = null,
        #[Option('Désactive le prix fondateur')] bool $sansFondateur = false,
    ): int {
        if (null === $this->content->findTrack($parcours)) {
            $io->error(sprintf('Parcours « %s » inconnu (installés : %s).', $parcours, implode(', ', array_keys($this->content->tracks()))));

            return Command::FAILURE;
        }
        try {
            $pricing = $this->pricings->findOneByTrack($parcours) ?? new TrackPricing($parcours);
            $pricing->setNormalPrice(self::cents($prix));
            if (null !== $fondateur) {
                $pricing->setFounderPrice(self::cents($fondateur))->setFounderActive(true);
            }
            if (null !== $quota) {
                $pricing->setFounderQuotaMax($quota);
            }
            if (null !== $fin) {
                $pricing->setFounderEndsAt(new \DateTimeImmutable($fin));
            }
            if ($sansFondateur) {
                $pricing->setFounderActive(false);
            }
        } catch (\InvalidArgumentException|\DateMalformedStringException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $violations = $this->validator->validate($pricing);
        if (\count($violations)) {
            foreach ($violations as $violation) {
                $io->error($violation->getPropertyPath().' : '.$violation->getMessage());
            }

            return Command::FAILURE;
        }
        $this->entityManager->persist($pricing);
        $this->entityManager->flush();
        $io->success(sprintf('« %s » : %s € TTC%s.', $parcours, number_format($pricing->getNormalPrice() / 100, 2, ',', ' '), $pricing->isFounderActive() ? sprintf(', fondateur %s €', number_format((int) $pricing->getFounderPrice() / 100, 2, ',', ' ')) : ''));

        return Command::SUCCESS;
    }

    /** « 79 », « 79.00 » ou « 49,90 » → centimes. */
    private static function cents(string $euros): int
    {
        if (!preg_match('/^(\d+)(?:[.,](\d{1,2}))?$/', trim($euros), $m)) {
            throw new \InvalidArgumentException(sprintf('Montant illisible : « %s » (exemple : 79 ou 49,90).', $euros));
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }
}
