<?php

namespace App\Controller\Admin;

use App\Content\ContentRepository;
use App\Entity\TrackPricing;
use App\Payment\TrackPricer;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Prix des parcours installés. Un parcours sans tarif, ou à 0 €, est gratuit pour tout compte.
 * Les prix sont TTC : Stripe Tax en déduit la TVA du pays de l'acheteur.
 */
#[AdminRoute(path: '/tarifs', name: 'pricing')]
final class TrackPricingCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ContentRepository $content,
        private readonly TrackPricer $pricer,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return TrackPricing::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Tarif')
            ->setEntityLabelInPlural('Tarifs des parcours')
            ->setDefaultSort(['trackId' => 'ASC'])
            ->setHelp(Crud::PAGE_INDEX, 'Prix TTC. Sans tarif, ou à 0 €, un parcours est gratuit pour tout compte. Le premier chapitre de chaque parcours reste libre, même sans compte.');
    }

    public function configureFields(string $pageName): iterable
    {
        $tracks = [];
        foreach ($this->content->tracks() as $track) {
            $tracks[$track->title.($track->isRestricted() ? ' (en préparation)' : '')] = $track->id;
        }

        yield ChoiceField::new('trackId', 'Parcours')->setChoices($tracks)->setDisabled(Crud::PAGE_EDIT === $pageName);
        yield MoneyField::new('normalPrice', 'Prix normal')->setCurrency('EUR')->setStoredAsCents()->setHelp('0 : gratuit, pas de bouton d\'achat.');
        yield TextField::new('trackId', 'Prix affiché')
            ->onlyOnIndex()
            ->setSortable(false)
            ->setTemplatePath('admin/field/current_price.html.twig')
            ->setCustomOption('quotes', $this->quotes());
        yield FormField::addFieldset('Prix fondateur');
        yield MoneyField::new('founderPrice', 'Prix fondateur')->setCurrency('EUR')->setStoredAsCents();
        yield BooleanField::new('founderActive', 'Fondateur actif')->renderAsSwitch(false);
        yield DateTimeField::new('founderEndsAt', 'Fin du prix fondateur')->setHelp('Vide : sans date limite.');
        yield IntegerField::new('founderQuotaMax', 'Quota fondateur')->setHelp('Nombre d\'achats payés au prix fondateur avant qu\'il cesse (un remboursement libère la place). Vide : sans quota.');
        yield DateTimeField::new('updatedAt', 'Modifié le')->onlyOnIndex();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    /** @return array<string, \App\Payment\PriceQuote> prix courant par parcours tarifé */
    private function quotes(): array
    {
        $quotes = [];
        foreach ($this->content->tracks() as $track) {
            $quotes[$track->id] = $this->pricer->quote($track);
        }

        return $quotes;
    }
}
