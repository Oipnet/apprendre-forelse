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
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
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
use Symfony\Component\HttpFoundation\Response;

/**
 * Retours des apprenants (bouton « Un avis ? ») : on les lit, on les marque traités, on les supprime.
 *
 * @extends AbstractCrudController<Feedback>
 */
#[AdminRoute(path: '/retours', name: 'feedback')]
final class FeedbackCrudController extends AbstractCrudController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Feedback::class;
    }

    /** @return array<string, FeedbackKind> */
    private static function kinds(): array
    {
        return array_combine(array_map(static fn (FeedbackKind $k) => $k->label(), FeedbackKind::cases()), FeedbackKind::cases());
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
        yield ChoiceField::new('kind', 'Type')->renderAsBadges([
            FeedbackKind::Bug->name => 'danger',
            FeedbackKind::Unclear->name => 'warning',
            FeedbackKind::TooHard->name => 'warning',
            FeedbackKind::TooEasy->name => 'info',
            FeedbackKind::Other->name => 'secondary',
        ]);
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
            ->add(ChoiceFilter::new('kind', 'Type')->setChoices(array_map(static fn (FeedbackKind $k) => $k->value, self::kinds())))
            ->add(EntityFilter::new('user', 'Apprenant'))
            ->add(TextFilter::new('exerciseId', 'Exercice'))
            ->add(BooleanFilter::new('completed', 'Réussi'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $handle = Action::new('handle', 'Marquer traité', 'fa fa-check')
            ->linkToCrudAction('handle')
            ->displayIf(static fn (Feedback $f) => !$f->isHandled());
        $reopen = Action::new('reopen', 'Rouvrir', 'fa fa-rotate-left')
            ->linkToCrudAction('reopen')
            ->displayIf(static fn (Feedback $f) => $f->isHandled());

        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $handle)
            ->add(Crud::PAGE_INDEX, $reopen)
            ->add(Crud::PAGE_DETAIL, $handle)
            ->add(Crud::PAGE_DETAIL, $reopen);
    }

    /** @param AdminContext<Feedback> $context */
    #[AdminRoute('/{entityId}/traite', name: 'handle', options: ['methods' => ['POST', 'GET']])]
    public function handle(AdminContext $context): Response
    {
        return $this->setHandled($context, true);
    }

    /** @param AdminContext<Feedback> $context */
    #[AdminRoute('/{entityId}/rouvrir', name: 'reopen', options: ['methods' => ['POST', 'GET']])]
    public function reopen(AdminContext $context): Response
    {
        return $this->setHandled($context, false);
    }

    /** @param AdminContext<Feedback> $context */
    private function setHandled(AdminContext $context, bool $handled): Response
    {
        $feedback = $context->getEntity()->getInstance();
        if (!$feedback instanceof Feedback) {
            throw $this->createNotFoundException();
        }
        $feedback->setHandled($handled);
        $this->entityManager->flush();
        $this->addFlash('success', $handled ? 'Retour marqué comme traité.' : 'Retour rouvert.');

        // Retour à la liste ou au détail d'où l'on vient, tant que cela reste sur cette origine.
        $referer = $context->getRequest()->headers->get('referer', '');
        $sameOrigin = str_starts_with($referer, $context->getRequest()->getSchemeAndHttpHost().'/');

        return $this->redirect($sameOrigin ? $referer : $this->generateUrl('admin_feedback_index'));
    }
}
