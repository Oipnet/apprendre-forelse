<?php

namespace App\Controller\Admin;

use App\Cohort\CohortAccessSync;
use App\Cohort\CohortQuoteEstimator;
use App\Content\ContentRepository;
use App\Content\Track;
use App\Entity\Cohort;
use App\Entity\FundingMode;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Cohortes : créées ici, rejointes à l'inscription avec leur code (ou le lien d'invitation). */
#[AdminRoute(path: '/cohortes', name: 'cohorts')]
final class CohortCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly ContentRepository $content,
        private readonly UserRepository $users,
        private readonly CohortQuoteEstimator $estimator,
        private readonly CohortAccessSync $cohortAccess,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Cohort::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Cohorte')
            ->setEntityLabelInPlural('Cohortes')
            ->setSearchFields(['name', 'code', 'note'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setHelp(Crud::PAGE_INDEX, 'Envoyez le lien d\'invitation (ou le code) aux apprenants : il pré-remplit le formulaire d\'inscription. Une cohorte inactive n\'accepte plus d\'inscriptions.')
            // La fiche affiche le financement et l'estimation du devis sous les champs (voir configureResponseParameters).
            ->overrideTemplate('crud/detail', 'admin/cohort_detail.html.twig');
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_DETAIL === $responseParameters->get('pageName')) {
            $cohort = $responseParameters->get('entity')->getInstance();
            $responseParameters->set('quote', $cohort instanceof Cohort ? $this->estimator->estimate($cohort) : null);
        }

        return $responseParameters;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom')->setHelp('Ex. « BUT Info Annecy 2026 ».');
        // Le code, et en lecture le lien d'invitation qui le pré-remplit (à copier dans un message aux apprenants).
        yield TextField::new('code', 'Code d\'invitation')
            ->setHelp('Minuscules, chiffres et tirets : ce que l\'apprenant saisit à l\'inscription.')
            ->formatValue(fn ($value, Cohort $cohort) => sprintf('<code>%s</code><br><a href="%2$s" target="_blank" rel="noopener">%2$s</a>', htmlspecialchars((string) $value), htmlspecialchars($this->invitationUrl($cohort))))
            ->renderAsHtml();
        yield BooleanField::new('active', 'Inscriptions ouvertes');
        yield AssociationField::new('users', 'Apprenants')->hideOnForm();
        if (\in_array($pageName, [Crud::PAGE_INDEX, Crud::PAGE_DETAIL], true)) {
            // Gabarits maison : « Tous » plutôt que « vide » quand aucun parcours n'est choisi.
            yield ChoiceField::new('availableTrackIds', 'Parcours disponibles')
                ->setChoices($this->trackChoices())
                ->allowMultipleChoices()
                ->renderAsBadges(['info'])
                ->setSortable(false)
                ->setTemplatePath('admin/field/cohort_tracks.html.twig');
            yield AssociationField::new('chefs', 'Chefs de cohorte')
                ->setSortable(false)
                ->setTemplatePath('admin/field/cohort_chefs.html.twig');
        } else {
            // Tous les parcours installés, y compris ceux en préparation : les cocher les ouvre à cette cohorte.
            yield ChoiceField::new('availableTrackIds', 'Parcours disponibles')
                ->setChoices($this->trackChoices())
                ->allowMultipleChoices()
                ->renderExpanded()
                ->setHelp('Aucun coché : la cohorte propose tous les parcours publics. Un parcours retiré reste accessible aux apprenants qui l\'ont commencé.');
            // Les chefs se choisissent parmi les comptes qui ont le rôle (à donner sur la fiche apprenant).
            yield AssociationField::new('chefs', 'Chefs de cohorte')
                ->setFormTypeOption('by_reference', false)
                ->setQueryBuilder(fn (QueryBuilder $query) => $query
                    ->andWhere('entity.id IN (:chefs)')
                    ->setParameter('chefs', $this->users->findIdsWithRole(User::ROLE_CHEF_COHORTE) ?: [0])
                    ->orderBy('entity.displayName', 'ASC'))
                ->setHelp('Comptes ayant le rôle « Chef de cohorte » (fiche apprenant) : ils suivent la cohorte et choisissent ses parcours.');
        }
        yield TextareaField::new('note', 'Note')->hideOnIndex()->setHelp('Contact, contexte, dates du pilote…');
        yield FormField::addFieldset('Financement')->onlyOnForms();
        yield ChoiceField::new('fundingMode', 'Financement')
            ->setChoices(array_combine(array_map(static fn (FundingMode $m) => $m->label(), FundingMode::cases()), FundingMode::cases()))
            ->renderAsBadges([FundingMode::Institution->name => 'info', FundingMode::Learners->name => 'secondary'])
            ->setHelp('Établissement : chaque apprenant reçoit l\'accès aux parcours cochés, aux dates ci-dessous (devis). Apprenants : chacun achète, au tarif de la cohorte s\'il est fixé.');
        yield DateField::new('accessStartsAt', 'Début des accès')->hideOnIndex();
        yield DateField::new('accessEndsAt', 'Fin des accès')->setHelp('Les accès de la cohorte se ferment ce jour-là ; la progression reste.');
        yield IntegerField::new('expectedHeadcount', 'Effectif prévu')->hideOnIndex()->setHelp('Base de l\'estimation du devis ; le chef de cohorte peut le modifier.');
        yield MoneyField::new('learnerPrice', 'Tarif apprenant')->setCurrency('EUR')->setStoredAsCents()->hideOnIndex()
            ->setHelp('Mode apprenants : prix TTC par apprenant et par parcours. Vide : prix courant du parcours.');
        yield MoneyField::new('quoteAmount', 'Devis')->setCurrency('EUR')->setStoredAsCents()
            ->setHelp('Mode établissement : montant contractuel TTC. « Appliquer l\'estimation » le remplit depuis la fiche.');
        yield DateTimeField::new('createdAt', 'Créée le')->hideOnForm();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(BooleanFilter::new('active', 'Inscriptions ouvertes'))
            ->add(ChoiceFilter::new('fundingMode', 'Financement')->setChoices(array_combine(array_map(static fn (FundingMode $m) => $m->label(), FundingMode::cases()), array_column(FundingMode::cases(), 'value'))));
    }

    /** Les accès de la cohorte suivent ses parcours, ses dates et son mode de financement. */
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::persistEntity($entityManager, $entityInstance);
        $this->cohortAccess->sync($entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::updateEntity($entityManager, $entityInstance);
        $this->cohortAccess->sync($entityInstance);
    }

    /** Les accès ouverts par la cohorte ne lui survivent pas (la progression, si). */
    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->cohortAccess->revokeAll($entityInstance);
        parent::deleteEntity($entityManager, $entityInstance);
    }

    /** Copie l'estimation dans le devis : point de départ, à corriger avant de l'envoyer. */
    #[AdminRoute('/{entityId}/appliquer-estimation', name: 'apply_quote', options: ['methods' => ['POST']])]
    public function applyQuote(AdminContext $context, EntityManagerInterface $entityManager): Response
    {
        $cohort = $context->getEntity()->getInstance();
        if (!$cohort instanceof Cohort) {
            throw $this->createNotFoundException();
        }
        $quote = $this->estimator->estimate($cohort);
        $cohort->setQuoteAmount($quote->amount);
        $entityManager->flush();
        $this->addFlash('success', sprintf('Devis de « %s » : %s TTC, repris de l\'estimation.', $cohort->getName(), number_format($quote->amount / 100, 2, ',', ' ').' €'));

        return $this->redirectToRoute('admin_cohorts_detail', ['entityId' => $cohort->getId()]);
    }

    public function configureActions(Actions $actions): Actions
    {
        $progress = Action::new('progress', 'Avancement', 'fa fa-chart-simple')
            ->linkToRoute('admin_cohort', static fn (Cohort $cohort) => ['cohort' => $cohort->getCode()]);

        $applyQuote = Action::new('applyQuote', 'Appliquer l\'estimation', 'fa fa-calculator')
            ->linkToCrudAction('applyQuote')
            ->renderAsForm()
            ->displayIf(static fn (Cohort $cohort) => $cohort->isFundedByInstitution());

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $progress)
            ->add(Crud::PAGE_DETAIL, $progress)
            ->add(Crud::PAGE_DETAIL, $applyQuote);
    }

    /** @return array<string, string> titre (et état) du parcours => identifiant */
    private function trackChoices(): array
    {
        $choices = [];
        foreach ($this->content->tracks() as $track) {
            $choices[$this->trackLabel($track)] = $track->id;
        }

        return $choices;
    }

    private function trackLabel(Track $track): string
    {
        return $track->title.($track->isRestricted() ? ' (en préparation)' : '');
    }

    private function invitationUrl(Cohort $cohort): string
    {
        return $this->router->generate('app_register', ['code' => $cohort->getCode()], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
