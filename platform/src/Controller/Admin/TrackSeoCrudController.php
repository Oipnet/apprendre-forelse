<?php

namespace App\Controller\Admin;

use App\Content\ContentRepository;
use App\Entity\TrackSeo;
use App\Instance\Branding;
use App\Seo\SeoWriter;
use App\Seo\TrackSeoText;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Title et description d'une page de parcours, quand la génération automatique ne convient pas. Un champ vide garde
 * le texte généré, affiché dans la liste.
 *
 * @extends AbstractCrudController<TrackSeo>
 */
#[AdminRoute(path: '/referencement', name: 'track_seo')]
final class TrackSeoCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ContentRepository $content,
        private readonly SeoWriter $seo,
        private readonly Branding $branding,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return TrackSeo::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Référencement')
            ->setEntityLabelInPlural('Référencement des parcours')
            ->setDefaultSort(['trackId' => 'ASC'])
            ->setHelp(Crud::PAGE_INDEX, 'Title et description de la page d\'un parcours dans les moteurs de recherche. Sans saisie, ils sont générés : « Formation Symfony en ligne pour les devs PHP | '.$this->branding->name().' », puis le résumé du parcours et ses chiffres.');
    }

    public function configureFields(string $pageName): iterable
    {
        $tracks = [];
        foreach ($this->content->tracks() as $track) {
            $tracks[$track->title.($track->isRestricted() ? ' (en préparation)' : '')] = $track->id;
        }

        yield ChoiceField::new('trackId', 'Parcours')->setChoices($tracks)->setDisabled(Crud::PAGE_EDIT === $pageName);
        yield TextField::new('seoTitle', 'Title')
            ->setHelp(sprintf('%d caractères au plus, sans « | %s » (ajouté à l\'affichage). Vide : title généré.', TrackSeoText::TITLE_MAX, $this->branding->name()))
            ->setFormTypeOption('attr', ['maxlength' => TrackSeoText::TITLE_MAX]);
        yield TextareaField::new('seoDescription', 'Description')
            ->setHelp('155 caractères au plus, reprise en og:description. Vide : description générée.')
            ->setFormTypeOption('attr', ['maxlength' => 155, 'rows' => 3]);
        yield TextField::new('trackId', 'Title généré')->onlyOnIndex()->setSortable(false)
            ->formatValue(fn (?string $trackId) => $this->generatedTitle($trackId));
        yield DateTimeField::new('updatedAt', 'Modifié le')->onlyOnIndex();
    }

    private function generatedTitle(?string $trackId): string
    {
        $track = null === $trackId ? null : $this->content->findTrack($trackId);

        return null === $track ? '—' : TrackSeoText::generatedTitle($track->title, $this->seo->framework($track->environment));
    }
}
