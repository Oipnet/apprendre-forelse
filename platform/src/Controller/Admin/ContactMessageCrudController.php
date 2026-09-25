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
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
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
use Symfony\Component\HttpFoundation\Response;

/**
 * Messages de la page de contact et demandes des écoles : on les lit, on répond par email, on les marque traités.
 *
 * @extends AbstractCrudController<ContactMessage>
 */
#[AdminRoute(path: '/messages', name: 'contact_message')]
final class ContactMessageCrudController extends AbstractCrudController
{
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
        yield ChoiceField::new('subject', 'Objet')->renderAsBadges([
            ContactSubject::Organization->name => 'success',
            ContactSubject::Order->name => 'warning',
            ContactSubject::Bug->name => 'danger',
            ContactSubject::Question->name => 'info',
            ContactSubject::Other->name => 'secondary',
        ]);
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
        $subjects = [];
        foreach (ContactSubject::cases() as $subject) {
            $subjects[$subject->label()] = $subject->value;
        }

        return $filters
            ->add(BooleanFilter::new('handled', 'Traité'))
            ->add(ChoiceFilter::new('subject', 'Objet')->setChoices($subjects));
    }

    public function configureActions(Actions $actions): Actions
    {
        $handle = Action::new('handle', 'Marquer traité', 'fa fa-check')
            ->linkToCrudAction('handle')
            ->displayIf(static fn (ContactMessage $m) => !$m->isHandled());
        $reopen = Action::new('reopen', 'Rouvrir', 'fa fa-rotate-left')
            ->linkToCrudAction('reopen')
            ->displayIf(static fn (ContactMessage $m) => $m->isHandled());

        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $handle)
            ->add(Crud::PAGE_INDEX, $reopen)
            ->add(Crud::PAGE_DETAIL, $handle)
            ->add(Crud::PAGE_DETAIL, $reopen);
    }

    /** @param AdminContext<ContactMessage> $context */
    #[AdminRoute('/{entityId}/traite', name: 'handle', options: ['methods' => ['POST', 'GET']])]
    public function handle(AdminContext $context): Response
    {
        return $this->setHandled($context, true);
    }

    /** @param AdminContext<ContactMessage> $context */
    #[AdminRoute('/{entityId}/rouvrir', name: 'reopen', options: ['methods' => ['POST', 'GET']])]
    public function reopen(AdminContext $context): Response
    {
        return $this->setHandled($context, false);
    }

    /** @param AdminContext<ContactMessage> $context */
    private function setHandled(AdminContext $context, bool $handled): Response
    {
        $message = $context->getEntity()->getInstance();
        if (!$message instanceof ContactMessage) {
            throw $this->createNotFoundException();
        }
        $message->setHandled($handled);
        $this->entityManager->flush();
        $this->addFlash('success', $handled ? 'Message marqué comme traité.' : 'Message rouvert.');

        $referer = $context->getRequest()->headers->get('referer', '');
        $sameOrigin = str_starts_with($referer, $context->getRequest()->getSchemeAndHttpHost().'/');

        return $this->redirect($sameOrigin ? $referer : $this->generateUrl('admin_contact_message_index'));
    }
}
