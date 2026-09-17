<?php

namespace App\Command;

use App\Cohort\CohortAccessSync;
use App\Content\ContentRepository;
use App\Content\Track;
use App\Entity\TrackAccess;
use App\Entity\User;
use App\Repository\CohortRepository;
use App\Repository\TrackAccessRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Passage aux parcours payants (0.8.0) : chacun garde ce qu'il avait avant que des prix soient fixés.
 *
 *  - Les cohortes (déjà passées par la migration en financement « établissement », accès sur un an) qui n'avaient
 *    choisi aucun parcours proposaient tous les parcours publics : cette liste est figée. Leurs apprenants
 *    reçoivent un accès « cohorte » à chaque parcours choisi.
 *  - Les apprenants sans cohorte accédaient à tous les parcours publics : ils reçoivent un accès offert, à vie.
 *
 * À lancer une fois après la migration, AVANT de fixer des prix (app:tarif). Sans danger si on la relance :
 * rien n'est créé en double.
 */
#[AsCommand(name: 'app:acces:initialiser', description: 'Donne aux apprenants et cohortes existants les accès qu\'ils avaient avant les parcours payants.')]
final readonly class AccessInitCommand
{
    public const string GIFT_NOTE = 'Accès conservé au passage aux parcours payants';

    public function __construct(
        private ContentRepository $content,
        private CohortRepository $cohorts,
        private UserRepository $users,
        private TrackAccessRepository $accesses,
        private CohortAccessSync $cohortAccess,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Affiche ce qui serait fait, sans rien enregistrer')] bool $dryRun = false,
    ): int {
        $publicTrackIds = array_values(array_map(static fn (Track $track) => $track->id, array_filter($this->content->tracks(), static fn (Track $track) => !$track->isRestricted())));
        $io->writeln('Parcours publics : '.($publicTrackIds ? implode(', ', $publicTrackIds) : 'aucun'));

        foreach ($this->cohorts->findAllOrdered() as $cohort) {
            if ($cohort->isFundedByInstitution() && !$cohort->hasTrackSelection()) {
                $io->writeln(sprintf(' · cohorte « %s » : tous les parcours publics, désormais choisis explicitement', $cohort->getName()));
                $cohort->setAvailableTrackIds($publicTrackIds);
            }
            $io->writeln(sprintf(' · cohorte « %s » : %d apprenant(s) × %s', $cohort->getName(), $cohort->getUsers()->count(), $cohort->isFundedByInstitution() ? implode(', ', $cohort->getAvailableTrackIds()) : 'aucun accès (financée par les apprenants)'));
            if (!$dryRun) {
                $this->cohortAccess->sync($cohort);
            }
        }

        $now = $this->clock->now();
        $gifts = 0;
        foreach ($this->users->findBy(['cohort' => null]) as $user) {
            if (array_intersect([User::ROLE_ADMIN, User::ROLE_AUTEUR], $user->getRoles())) {
                continue;
            }
            foreach ($publicTrackIds as $trackId) {
                if ($this->accesses->hasAnyAccess($user, $trackId)) {
                    continue;
                }
                ++$gifts;
                if (!$dryRun) {
                    $this->entityManager->persist(TrackAccess::gift($user, $trackId, $now, note: self::GIFT_NOTE));
                }
            }
        }
        $io->writeln(sprintf(' · apprenants sans cohorte : %d accès offert(s) à vie', $gifts));

        if ($dryRun) {
            $io->note('Rien n\'a été enregistré (--dry-run).');

            return Command::SUCCESS;
        }
        $this->entityManager->flush();
        $io->success('Accès initialisés. Vous pouvez maintenant fixer les prix (app:tarif ou /admin → Tarifs).');

        return Command::SUCCESS;
    }
}
