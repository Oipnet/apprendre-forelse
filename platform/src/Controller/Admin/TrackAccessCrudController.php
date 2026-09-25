<?php

namespace App\Controller\Admin;

use App\Content\ContentRepository;
use App\Entity\AccessSource;
use App\Entity\TrackAccess;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accès aux parcours, toutes sources confondues. On n'en crée ici que des accès offerts (« Offrir un parcours ») ;
 * les autres viennent des achats et des cohortes. Un accès ne se supprime pas : il se révoque.
 *
 * @extends AbstractCrudController<TrackAccess>
 */
#[AdminRoute(path: '/acces', name: 'accesses')]
final class TrackAccessCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ContentRepository $content,
        private readonly UserRepository $users,
        private readonly ClockInterface $clock,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return TrackAccess::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Accès')
            ->setEntityLabelInPlural('Accès aux parcours')
            ->setPageTitle(Crud::PAGE_NEW, 'Offrir un parcours')
            ->setSearchFields(['trackId', 'note', 'user.displayName', 'user.email'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    /** Offert, maintenant, à l'apprenant choisi (prérempli depuis sa fiche). */
    public function createEntity(string $entityFqcn): TrackAccess
    {
        $request = $this->getContext()?->getRequest();
        $user = null !== $request && $request->query->getInt('user') ? $this->users->find($request->query->getInt('user')) : null;

        return TrackAccess::gift($user ?? new User(), '', $this->clock->now());
    }

    public function configureFields(string $pageName): iterable
    {
        $tracks = [];
        foreach ($this->content->tracks() as $track) {
            $tracks[$track->title] = $track->id;
        }

        yield AssociationField::new('user', 'Apprenant')->setDisabled(Crud::PAGE_NEW !== $pageName)->autocomplete();
        yield ChoiceField::new('trackId', 'Parcours')->setChoices($tracks)->setDisabled(Crud::PAGE_NEW !== $pageName);
        yield ChoiceField::new('source', 'Source')->hideOnForm()->renderAsBadges([
            AccessSource::Purchase->name => 'success',
            AccessSource::Cohort->name => 'info',
            AccessSource::Gift->name => 'secondary',
        ]);
        yield AssociationField::new('cohort', 'Cohorte')->hideOnForm();
        yield AssociationField::new('purchase', 'Achat')->onlyOnDetail();
        yield DateTimeField::new('startsAt', 'Début')->hideOnForm();
        yield DateTimeField::new('endsAt', 'Fin')->setHelp('Vide : à vie.')->formatValue(static fn ($value) => $value ?? 'à vie');
        yield TextField::new('note', 'Note')->setHelp('Pourquoi cet accès est offert (bêta, partenaire…).');
        yield DateTimeField::new('createdAt', 'Créé le')->onlyOnDetail();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('user', 'Apprenant'))
            ->add(ChoiceFilter::new('source', 'Source')->setChoices(array_combine(array_map(static fn (AccessSource $s) => $s->label(), AccessSource::cases()), array_column(AccessSource::cases(), 'value'))))
            ->add(EntityFilter::new('cohort', 'Cohorte'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $revoke = Action::new('revoke', 'Révoquer', 'fa fa-ban')
            ->linkToCrudAction('revoke')
            ->renderAsForm()
            ->setHtmlAttributes(['onsubmit' => "return confirm('Fermer cet accès maintenant ? La progression est conservée.')"])
            ->displayIf(fn (TrackAccess $access) => $access->isActive($this->clock->now()));

        return $actions
            ->disable(Action::DELETE)
            ->update(Crud::PAGE_INDEX, Action::NEW, static fn (Action $action) => $action->setLabel('Offrir un parcours')->setIcon('fa fa-gift'))
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_DETAIL, $revoke)
            // Seule la date de fin se modifie : prolonger un accès offert, ou en fixer la fin.
            ->update(Crud::PAGE_INDEX, Action::EDIT, static fn (Action $action) => $action->setLabel('Modifier la fin'));
    }

    /** @param AdminContext<TrackAccess> $context */
    #[AdminRoute('/{entityId}/revoquer', name: 'revoke', options: ['methods' => ['POST']])]
    public function revoke(AdminContext $context, EntityManagerInterface $entityManager): Response
    {
        $access = $context->getEntity()->getInstance();
        if (!$access instanceof TrackAccess) {
            throw $this->createNotFoundException();
        }
        $access->revoke($this->clock->now());
        $entityManager->flush();
        $this->addFlash('success', 'Accès révoqué ; la progression de l\'apprenant est conservée.');

        return $this->redirectToRoute('admin_accesses_detail', ['entityId' => $access->getId()]);
    }
}
