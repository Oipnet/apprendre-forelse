<?php

namespace App\Controller\Admin;

use App\Content\ContentRepository;
use App\Entity\PriceKind;
use App\Entity\Purchase;
use App\Entity\PurchaseStatus;
use App\Payment\PaymentException;
use App\Payment\PurchaseFulfillment;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use Symfony\Component\HttpFoundation\Response;

/** Achats individuels : lecture seule, sauf le remboursement. Un achat ne se crée ni ne se supprime ici. */
#[AdminRoute(path: '/achats', name: 'purchases')]
final class PurchaseCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ContentRepository $content,
        private readonly PurchaseFulfillment $fulfillment,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Purchase::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Achat')
            ->setEntityLabelInPlural('Achats')
            ->setSearchFields(['customerEmail', 'trackId', 'stripeSessionId', 'stripePaymentIntentId'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setHelp(Crud::PAGE_INDEX, '« En attente » : session Stripe ouverte, jamais confirmée par le webhook (paiement abandonné, ou webhook à vérifier).');
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('createdAt', 'Créé le');
        yield AssociationField::new('user', 'Apprenant');
        yield EmailField::new('customerEmail', 'Email')->hideOnIndex();
        yield ChoiceField::new('trackId', 'Parcours')->setChoices($this->trackChoices());
        yield ChoiceField::new('status', 'Statut')->renderAsBadges([
            PurchaseStatus::Pending->name => 'secondary',
            PurchaseStatus::Abandoned->name => 'light',
            PurchaseStatus::Paid->name => 'success',
            PurchaseStatus::Refunded->name => 'warning',
        ]);
        yield ChoiceField::new('priceKind', 'Prix appliqué');
        yield MoneyField::new('price', 'Prix')->setCurrency('EUR')->setStoredAsCents();
        yield MoneyField::new('amountPaid', 'Payé')->setCurrency('EUR')->setStoredAsCents();
        yield AssociationField::new('cohort', 'Cohorte')->hideOnIndex();
        yield DateTimeField::new('paidAt', 'Payé le')->hideOnIndex();
        yield DateTimeField::new('refundedAt', 'Remboursé le')->hideOnIndex();
        yield UrlField::new('receiptUrl', 'Reçu')->hideOnIndex();
        yield TextField::new('stripeSessionId', 'Session Stripe')->onlyOnDetail();
        yield TextField::new('stripePaymentIntentId', 'Paiement Stripe')->onlyOnDetail();
        yield TextField::new('stripeRefundId', 'Remboursement Stripe')->onlyOnDetail();
        yield TextareaField::new('withdrawalWaiverText', 'Renonciation à la rétractation')->onlyOnDetail();
        yield DateTimeField::new('withdrawalWaiverAcceptedAt', 'Acceptée le')->onlyOnDetail();
        yield TextField::new('termsVersion', 'Version des CGV acceptée')->onlyOnDetail();
        yield DateTimeField::new('termsAcceptedAt', 'CGV acceptées le')->onlyOnDetail();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices(array_combine(array_map(static fn (PurchaseStatus $s) => $s->label(), PurchaseStatus::cases()), array_column(PurchaseStatus::cases(), 'value'))))
            ->add(ChoiceFilter::new('trackId', 'Parcours')->setChoices($this->trackChoices()))
            ->add(ChoiceFilter::new('priceKind', 'Prix appliqué')->setChoices(array_combine(array_map(static fn (PriceKind $k) => $k->label(), PriceKind::cases()), array_column(PriceKind::cases(), 'value'))))
            ->add(DateTimeFilter::new('createdAt', 'Période'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $refund = Action::new('refund', 'Rembourser', 'fa fa-rotate-left')
            ->linkToCrudAction('refund')
            ->renderAsForm()
            ->setHtmlAttributes(['onsubmit' => "return confirm('Rembourser intégralement cet achat chez Stripe et fermer l\\'accès au parcours ?')"])
            ->displayIf(static fn (Purchase $purchase) => $purchase->isPaid());

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_DETAIL, $refund);
    }

    /** Rembourse chez Stripe, passe l'achat en « remboursé » et révoque l'accès (la progression reste). */
    #[AdminRoute('/{entityId}/rembourser', name: 'refund', options: ['methods' => ['POST']])]
    public function refund(AdminContext $context): Response
    {
        $purchase = $context->getEntity()->getInstance();
        if (!$purchase instanceof Purchase) {
            throw $this->createNotFoundException();
        }
        try {
            $this->fulfillment->refund($purchase);
            $this->addFlash('success', sprintf('Achat n° %d remboursé ; l\'accès au parcours est fermé.', $purchase->getId()));
        } catch (PaymentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('admin_purchases_detail', ['entityId' => $purchase->getId()]);
    }

    /** @return array<string, string> */
    private function trackChoices(): array
    {
        $choices = [];
        foreach ($this->content->tracks() as $track) {
            $choices[$track->title] = $track->id;
        }

        return $choices;
    }
}
