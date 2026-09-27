<?php

namespace App\Controller\Admin;

use App\Entity\ContactMessage;
use App\Entity\ContactSubject;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;

/**
 * Messages de la page de contact et demandes des écoles : on les lit, on répond par email, on les marque traités.
 *
 * @extends AbstractCrudController<ContactMessage>
 */
#[AdminRoute(path: '/messages', name: 'contact_message')]
final class ContactMessageCrudController extends AbstractCrudController
{
    use HandledCrudActions;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public static function getEntityFqcn(): string
    {
        return ContactMessage::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Message')
            ->setEntityLabelInPlural('Messages')
            ->setSearchFields(['name', 'email', 'organization', 'message'])
            ->setDefaultSort(['handled' => 'ASC', 'createdAt' => 'DESC'])
            ->setHelp(Crud::PAGE_INDEX, 'Chaque message est aussi parti par email (CONTACT_EMAIL) : y répondre écrit directement à l\'expéditeur.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('createdAt', 'Reçu le');
        yield ChoiceField::new('subject', 'Objet')->renderAsBadges(ContactSubject::badges());
        yield TextField::new('name', 'Nom');
        yield EmailField::new('email', 'Email');
        yield TextField::new('organization', 'Établissement');
        yield IntegerField::new('headcount', 'Effectif')->onlyOnDetail();
        yield AssociationField::new('user', 'Compte')->onlyOnDetail();
        yield TextareaField::new('message', 'Message')->setMaxLength(90)->onlyOnIndex();
        yield TextareaField::new('message', 'Message')->onlyOnDetail();
        yield BooleanField::new('handled', 'Traité')->renderAsSwitch(false);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(BooleanFilter::new('handled', 'Traité'))
            ->add(ChoiceFilter::new('subject', 'Objet')->setChoices(ContactSubject::valueChoices()));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->addHandledActions($actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL));
    }

    protected function handledNotice(bool $handled): string
    {
        return $handled ? 'Message marqué comme traité.' : 'Message rouvert.';
    }

    protected function handledIndexRoute(): string
    {
        return 'admin_contact_message_index';
    }

    protected function handledEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }
}
