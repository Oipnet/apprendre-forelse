<?php

namespace App\Controller\Admin;

use App\Entity\Feedback;
use App\Entity\FeedbackKind;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

/**
 * Retours des apprenants (bouton « Un avis ? ») : on les lit, on les marque traités, on les supprime.
 *
 * @extends AbstractCrudController<Feedback>
 */
#[AdminRoute(path: '/retours', name: 'feedback')]
final class FeedbackCrudController extends AbstractCrudController
{
    use HandledCrudActions;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Feedback::class;
    }


    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Retour')
            ->setEntityLabelInPlural('Retours')
            ->setSearchFields(['message', 'exerciseId', 'user.displayName'])
            ->setDefaultSort(['handled' => 'ASC', 'createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('createdAt', 'Reçu le');
        yield AssociationField::new('user', 'Apprenant');
        yield TextField::new('user.cohort', 'Cohorte');
        yield TextField::new('trackId', 'Parcours')->formatValue(static fn (?string $trackId) => $trackId ?? 'Pratique')->onlyOnDetail();
        yield TextField::new('exerciseId', 'Exercice');
        // Les choix viennent de l'enum (enumType Doctrine) ; les badges sont indexés par nom de cas.
        yield ChoiceField::new('kind', 'Type')->renderAsBadges(FeedbackKind::badges());
        yield TextareaField::new('message', 'Message')->setMaxLength(90)->onlyOnIndex();
        yield TextareaField::new('message', 'Message')->onlyOnDetail();
        yield IntegerField::new('hintsUsed', 'Indices');
        yield BooleanField::new('completed', 'Réussi')->renderAsSwitch(false);
        yield BooleanField::new('handled', 'Traité')->renderAsSwitch(false);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(BooleanFilter::new('handled', 'Traité'))
            ->add(ChoiceFilter::new('kind', 'Type')->setChoices(FeedbackKind::valueChoices()))
            ->add(EntityFilter::new('user', 'Apprenant'))
            ->add(TextFilter::new('exerciseId', 'Exercice'))
            ->add(BooleanFilter::new('completed', 'Réussi'));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->addHandledActions($actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL));
    }

    protected function handledNotice(bool $handled): string
    {
        return $handled ? 'Retour marqué comme traité.' : 'Retour rouvert.';
    }

    protected function handledIndexRoute(): string
    {
        return 'admin_feedback_index';
    }

    protected function handledEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }
}
