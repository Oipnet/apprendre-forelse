<?php

namespace App\Controller\Admin;

use App\Entity\WaitlistEntry;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;

/**
 * Adresses laissées sur la page d'accueil pour être prévenues de l'ouverture. Lecture et suppression seulement.
 *
 * @extends AbstractCrudController<WaitlistEntry>
 */
#[AdminRoute(path: '/liste-d-attente', name: 'waitlist')]
final class WaitlistEntryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return WaitlistEntry::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Inscrit')
            ->setEntityLabelInPlural('Liste d\'attente')
            ->setSearchFields(['email'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setHelp(Crud::PAGE_INDEX, 'Personnes à prévenir à l\'ouverture. Une adresse n\'apparaît qu\'une fois.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield EmailField::new('email', 'Adresse');
        yield DateTimeField::new('createdAt', 'Inscrite le');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DETAIL);
    }
}
