<?php

namespace App\Controller\Admin;

use App\Admin\BetaStats;
use App\Cohort\CohortAccessSync;
use App\Entity\Cohort;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/** Comptes : la création passe par l'inscription du site, l'admin corrige (cohorte, rôles) ou supprime (RGPD). */
#[AdminRoute(path: '/apprenants', name: 'users')]
final class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly AdminUrlGenerator $urls,
        private readonly BetaStats $stats,
        private readonly CohortAccessSync $cohortAccess,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Apprenant')
            ->setEntityLabelInPlural('Apprenants')
            ->setSearchFields(['displayName', 'email', 'cohort.name', 'cohort.code'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            // La fiche affiche l'avancement de l'apprenant sous ses champs (voir configureResponseParameters).
            ->overrideTemplate('crud/detail', 'admin/user_detail.html.twig');
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_DETAIL === $responseParameters->get('pageName')) {
            $user = $responseParameters->get('entity')->getInstance();
            $responseParameters->set('learner', $user instanceof User ? $this->stats->learner($user) : null);
        }

        return $responseParameters;
    }

    /** Changer la cohorte d'un apprenant ferme les accès de l'ancienne et ouvre ceux de la nouvelle. */
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $previous = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance)['cohort'] ?? null;
        // Le compte et ses accès ensemble : un échec ne laisse pas l'apprenant dans une cohorte sans ses parcours.
        $entityManager->wrapInTransaction(function () use ($entityManager, $entityInstance, $previous): void {
            parent::updateEntity($entityManager, $entityInstance);
            $current = $entityInstance->getCohort();
            if ($previous === $current) {
                return;
            }
            if ($previous instanceof Cohort) {
                $this->cohortAccess->leave($entityInstance, $previous);
            }
            if (null !== $current) {
                $this->cohortAccess->join($entityInstance, $current);
            }
        });
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('displayName', 'Pseudo');
        yield EmailField::new('email');
        yield AssociationField::new('cohort', 'Cohorte')->setHelp('Rejointe avec son code d\'invitation ; modifiable ici.');
        yield IntegerField::new('xp', 'XP')->hideOnForm();
        yield ChoiceField::new('roles', 'Rôles')
            ->setChoices(['Apprenant' => 'ROLE_USER', 'Auteur' => User::ROLE_AUTEUR, 'Chef de cohorte' => User::ROLE_CHEF_COHORTE, 'Admin' => User::ROLE_ADMIN])
            ->allowMultipleChoices()
            ->renderExpanded()
            ->renderAsBadges()
            ->setHelp('Auteur : sur des packs modifiables, l\'atelier exécute sur le serveur les tests qu\'il écrit, avec ses secrets à portée — un rôle d\'administrateur. Les packs de la production sont en lecture seule.');
        yield DateTimeField::new('createdAt', 'Inscrit le')->hideOnForm();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(EntityFilter::new('cohort', 'Cohorte'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $progress = Action::new('progress', 'Progression', 'fa fa-list-check')
            ->linkToUrl(fn (User $user) => $this->urls
                ->unsetAll()
                ->setController(ExerciseProgressCrudController::class)
                ->setAction(Action::INDEX)
                ->set('filters', ['user' => ['comparison' => '=', 'value' => $user->getId()]])
                ->generateUrl());

        $offer = Action::new('offer', 'Offrir un parcours', 'fa fa-gift')
            ->linkToUrl(fn (User $user) => $this->urls
                ->unsetAll()
                ->setController(TrackAccessCrudController::class)
                ->setAction(Action::NEW)
                ->set('user', $user->getId())
                ->generateUrl());
        $accesses = Action::new('accesses', 'Accès', 'fa fa-key')
            ->linkToUrl(fn (User $user) => $this->urls
                ->unsetAll()
                ->setController(TrackAccessCrudController::class)
                ->setAction(Action::INDEX)
                ->set('filters', ['user' => ['comparison' => '=', 'value' => $user->getId()]])
                ->generateUrl());

        // Se supprimer soi-même casserait la session en cours : on retire l'action pour son propre compte.
        $notSelf = fn (Action $action) => $action->displayIf(fn (User $user) => $user->getId() !== $this->getUser()?->getId());

        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $progress)
            ->add(Crud::PAGE_DETAIL, $progress)
            ->add(Crud::PAGE_DETAIL, $accesses)
            ->add(Crud::PAGE_DETAIL, $offer)
            ->update(Crud::PAGE_INDEX, Action::DELETE, $notSelf)
            ->update(Crud::PAGE_DETAIL, Action::DELETE, $notSelf);
    }
}
