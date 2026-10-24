<?php

namespace App\Controller\Admin;

use App\Certificate\CertificateRevocation;
use App\Entity\Certificate;
use App\Security\SafeRedirect;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NullFilter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Les certificats délivrés : les lire, en révoquer un (avec un motif), le rétablir après une contestation. Les
 * contestations arrivent dans les Messages (objet « Un litige sur un certificat ») ; on y répond par email. La fiche
 * montre l'historique des décisions et les contestations reçues.
 *
 * @extends AbstractCrudController<Certificate>
 */
#[AdminRoute(path: '/certificats', name: 'certificate')]
final class CertificateCrudController extends AbstractCrudController
{
    public function __construct(private readonly CertificateRevocation $revocation)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Certificate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Certificat')
            ->setEntityLabelInPlural('Certificats')
            ->setSearchFields(['code', 'holderName', 'trackTitle', 'user.email'])
            ->setDefaultSort(['issuedAt' => 'DESC'])
            ->overrideTemplate('crud/detail', 'admin/certificate_detail.html.twig');
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('issuedAt', 'Délivré le');
        yield TextField::new('code', 'Code');
        yield TextField::new('holderName', 'Titulaire');
        yield AssociationField::new('user', 'Compte');
        yield TextField::new('trackTitle', 'Parcours');
        yield IntegerField::new('exerciseCount', 'Exercices')->onlyOnDetail();
        yield IntegerField::new('withoutSolutionCount', 'Sans solution consultée')->onlyOnDetail();
        yield BooleanField::new('revoked', 'Révoqué')->renderAsSwitch(false);
        yield DateTimeField::new('revokedAt', 'Révoqué le')->onlyOnDetail();
        yield TextField::new('revocationReason', 'Motif')->onlyOnDetail();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(NullFilter::new('revokedAt', 'Révoqué')->setChoiceLabels('Valide', 'Révoqué'))
            ->add(DateTimeFilter::new('issuedAt', 'Délivré le'))
            ->add(EntityFilter::new('user.cohort', 'Cohorte'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $page = Action::new('page', 'Page publique', 'fa fa-up-right-from-square')
            ->linkToUrl(fn (Certificate $certificate) => $this->generateUrl('app_certificate', ['code' => $certificate->getCode()]));

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_DETAIL, $page);
    }

    /** @param AdminContext<Certificate> $context */
    #[AdminRoute('/{entityId}/revoquer', name: 'revoke', options: ['methods' => ['POST']])]
    #[IsCsrfTokenValid('certificate', tokenKey: '_token')]
    public function revoke(AdminContext $context): Response
    {
        $certificate = $this->certificate($context);
        $reason = trim($context->getRequest()->request->getString('reason'));
        if ('' === $reason) {
            $this->addFlash('danger', 'Indiquez le motif de la révocation : l\'apprenant le lira.');
        } else {
            $this->revocation->revoke($certificate, $reason);
            $this->addFlash('success', sprintf('Certificat %s révoqué, l\'apprenant est prévenu par email.', $certificate->getCode()));
        }

        return $this->back($context, $certificate);
    }

    /** @param AdminContext<Certificate> $context */
    #[AdminRoute('/{entityId}/retablir', name: 'reinstate', options: ['methods' => ['POST']])]
    #[IsCsrfTokenValid('certificate', tokenKey: '_token')]
    public function reinstate(AdminContext $context): Response
    {
        $certificate = $this->certificate($context);
        $note = trim($context->getRequest()->request->getString('note'));
        $this->revocation->reinstate($certificate, '' !== $note ? $note : null);
        $this->addFlash('success', sprintf('Certificat %s rétabli, l\'apprenant est prévenu par email.', $certificate->getCode()));

        return $this->back($context, $certificate);
    }

    /** @param AdminContext<Certificate> $context */
    private function certificate(AdminContext $context): Certificate
    {
        $certificate = $context->getEntity()->getInstance();

        return $certificate instanceof Certificate ? $certificate : throw $this->createNotFoundException();
    }

    /** @param AdminContext<Certificate> $context */
    private function back(AdminContext $context, Certificate $certificate): Response
    {
        $request = $context->getRequest();

        return $this->redirect(SafeRedirect::sameOrigin($request->headers->get('referer'), $request->getSchemeAndHttpHost())
            ?? $this->generateUrl('admin_certificate_detail', ['entityId' => $certificate->getId()]));
    }
}
