<?php

namespace App\Controller\Admin;

use App\Entity\ExerciseProgress;
use App\Entity\ProgressStatus;
use App\Entity\VerificationStatus;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

/**
 * Progression : en lecture seule, elle n'est écrite que par le playground.
 *
 * @extends AbstractCrudController<ExerciseProgress>
 */
#[AdminRoute(path: '/progression', name: 'progress')]
final class ExerciseProgressCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ExerciseProgress::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Progression')
            ->setEntityLabelInPlural('Progression')
            ->setSearchFields(['exerciseId', 'trackId', 'user.displayName', 'user.email'])
            ->setDefaultSort(['updatedAt' => 'DESC'])
            // Statuts en pastille : la couleur suffit, la liste reste lisible même chargée.
            ->setBadgeVariant('dot');
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('user', 'Apprenant');
        yield TextField::new('user.cohort', 'Cohorte');
        // Sans parcours : un exercice de Pratique.
        yield TextField::new('trackId', 'Parcours')->formatValue(static fn (?string $trackId) => $trackId ?? 'Pratique');
        yield TextField::new('exerciseId', 'Exercice');
        yield ChoiceField::new('status', 'Statut')->renderAsBadges(ProgressStatus::badges());
        yield IntegerField::new('hintsUsed', 'Indices');
        yield IntegerField::new('xpEarned', 'XP');
        yield ChoiceField::new('verification', 'Vérification')->renderAsBadges(VerificationStatus::badges())
            ->setHelp('Le code de la réussite, rejoué sur le serveur par le service « verificateur ».');
        yield TextField::new('verificationDetail', 'Détail de la vérification')->onlyOnDetail();
        yield DateTimeField::new('verifiedAt', 'Vérifiée le')->onlyOnDetail();
        yield DateTimeField::new('startedAt', 'Commencé le');
        yield DateTimeField::new('completedAt', 'Réussi le');
        yield DateTimeField::new('updatedAt', 'Dernière activité');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('user', 'Apprenant'))
            ->add(EntityFilter::new('user.cohort', 'Cohorte'))
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices(ProgressStatus::valueChoices()))
            ->add(ChoiceFilter::new('verification', 'Vérification')->setChoices(VerificationStatus::valueChoices()))
            ->add(TextFilter::new('trackId', 'Parcours'))
            ->add(TextFilter::new('exerciseId', 'Exercice'));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE)->add(Crud::PAGE_INDEX, Action::DETAIL);
    }
}
