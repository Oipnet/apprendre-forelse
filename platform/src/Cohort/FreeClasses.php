<?php

namespace App\Cohort;

use App\Content\Framework\FrameworkRegistry;
use App\Content\Practice;
use App\Content\PracticeVisibility;
use App\Entity\Cohort;
use App\Entity\User;
use App\Repository\CohortRepository;
use App\Service\RegistrationAlert;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Les classes gratuites : un formateur crée lui-même sa classe sur des exercices de la Pratique, sans passer par
 * l'équipe ni par un devis, et la suit depuis l'espace des chefs de cohorte. C'est l'essai proposé aux écoles.
 * Rien n'y est payé ni ouvert : la Pratique est publique, les parcours restent au prix courant (Cohort::markAsFreeClass).
 */
final readonly class FreeClasses
{
    /** Au-delà, le formateur nous écrit : une école qui en veut plus est une école à qui parler. */
    public const int MAX_PER_CHEF = 3;

    public function __construct(
        private CohortManagement $management,
        private CohortRepository $cohorts,
        private PracticeVisibility $practices,
        private FrameworkRegistry $frameworks,
        private EntityManagerInterface $entityManager,
        private RegistrationAlert $alert,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Les exercices que le formateur peut choisir, groupés par framework, pour un ChoiceType.
     *
     * @return array<string, array<string, string>> framework => [titre => identifiant]
     */
    public function choices(): array
    {
        $groups = [];
        foreach ($this->practices->practices() as $practice) {
            $groups[$this->frameworks->labelOf($practice->framework)][$practice->exercise->title] = $practice->exercise->id;
        }
        ksort($groups);

        return array_map(static function (array $group): array {
            ksort($group, \SORT_NATURAL | \SORT_FLAG_CASE);

            return $group;
        }, $groups);
    }

    /** @return list<Cohort> les classes gratuites de ce formateur */
    public function of(User $chef): array
    {
        return array_values(array_filter($this->cohorts->findByChef($chef), static fn (Cohort $cohort) => $cohort->isFreeClass()));
    }

    public function remaining(User $chef): int
    {
        return max(0, self::MAX_PER_CHEF - \count($this->of($chef)));
    }

    /**
     * Crée la classe, en fait le compte son chef (avec le rôle qui ouvre l'espace /cohorte) et prévient l'équipe.
     *
     * @param list<string> $practiceIds
     *
     * @throws CohortRuleViolation plus de classes que permis, ou aucun exercice choisi
     */
    public function create(User $chef, string $name, array $practiceIds): Cohort
    {
        if (0 === $this->remaining($chef)) {
            throw new CohortRuleViolation(sprintf('Vous avez déjà %d classes gratuites : écrivez-nous pour en ouvrir d\'autres.', self::MAX_PER_CHEF));
        }

        $cohort = (new Cohort($this->clock->now()))
            ->setName(trim($name))
            ->setCode(self::readableCode($name))
            ->markAsFreeClass()
            ->setPracticeIds($this->visible($practiceIds))
            ->addChef($chef)
            ->setNote('Classe gratuite créée par '.$chef->getEmail());
        if (!$cohort->getPracticeIds()) {
            throw new CohortRuleViolation('Choisissez au moins un exercice pour votre classe.');
        }
        if (!\in_array(User::ROLE_CHEF_COHORTE, $chef->getRoles(), true)) {
            $chef->setRoles([...$chef->getRoles(), User::ROLE_CHEF_COHORTE]);
        }

        // Le rôle du formateur s'enregistre avec la classe : CohortManagement::create écrit tout dans une transaction.
        $this->management->create($cohort);
        $this->alert->notifyFreeClass($cohort, $chef);

        return $cohort;
    }

    /**
     * @param list<string> $practiceIds
     *
     * @throws CohortRuleViolation aucun exercice choisi
     */
    public function choosePractices(Cohort $cohort, array $practiceIds): void
    {
        // Un exercice retiré de la Pratique depuis reste dans la classe tant que le formateur ne le décoche pas.
        $kept = array_diff($cohort->getPracticeIds(), array_keys($this->practices->practices()));
        $selection = [...$this->visible($practiceIds), ...$kept];
        if (!$selection) {
            throw new CohortRuleViolation('Une classe gratuite suit au moins un exercice : rien n\'a été enregistré.');
        }
        $cohort->setPracticeIds($selection);
        $this->entityManager->flush();
    }

    /**
     * Les exercices de la classe que l'utilisateur courant peut voir, dans l'ordre choisi.
     *
     * @return list<Practice>
     */
    public function practicesOf(Cohort $cohort): array
    {
        return array_values(array_filter(array_map($this->practices->find(...), $cohort->getPracticeIds())));
    }

    /**
     * Seuls les exercices visibles s'ajoutent : pas un exercice en préparation, ni un identifiant inventé.
     *
     * @param list<string> $practiceIds
     *
     * @return list<string>
     */
    private function visible(array $practiceIds): array
    {
        $visible = $this->practices->practices();

        return array_values(array_filter($practiceIds, static fn (string $id) => isset($visible[$id])));
    }

    /** « BTS SIO 2 — Bordeaux » → « bts-sio-2-bordeaux » ; la partie aléatoire s'ajoute à la création. */
    private static function readableCode(string $name): string
    {
        $slug = (new AsciiSlugger('fr'))->slug($name)->lower()->truncate(Cohort::READABLE_CODE_MAX)->trim('-')->toString();

        return '' !== $slug ? $slug : 'classe';
    }
}
