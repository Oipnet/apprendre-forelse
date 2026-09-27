<?php

namespace App\Controller\Admin;

use App\Entity\Handleable;
use App\Security\SafeRedirect;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Marquer traité » et « Rouvrir », pour un CRUD d'entités Handleable.
 *
 * Des formulaires POST, pas des liens : une écriture en GET se déclenche d'une simple image posée sur une
 * autre page, et échappe au contrôle d'origine des écritures (OriginIsolationListener).
 *
 * @phpstan-require-extends \EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController
 */
trait HandledCrudActions
{
    /** Le message affiché après le changement : « Retour marqué comme traité. » */
    abstract protected function handledNotice(bool $handled): string;

    /** La route de la liste, où revenir quand on ne sait pas d'où l'on vient. */
    abstract protected function handledIndexRoute(): string;

    abstract protected function handledEntityManager(): EntityManagerInterface;

    private function addHandledActions(Actions $actions): Actions
    {
        $handle = Action::new('handle', 'Marquer traité', 'fa fa-check')
            ->linkToCrudAction('handle')
            ->renderAsForm()
            ->displayIf(static fn (Handleable $entity) => !$entity->isHandled());
        $reopen = Action::new('reopen', 'Rouvrir', 'fa fa-rotate-left')
            ->linkToCrudAction('reopen')
            ->renderAsForm()
            ->displayIf(static fn (Handleable $entity) => $entity->isHandled());

        return $actions
            ->add(Crud::PAGE_INDEX, $handle)
            ->add(Crud::PAGE_INDEX, $reopen)
            ->add(Crud::PAGE_DETAIL, $handle)
            ->add(Crud::PAGE_DETAIL, $reopen);
    }

    /** @param AdminContext<Handleable> $context */
    #[AdminRoute('/{entityId}/traite', name: 'handle', options: ['methods' => ['POST']])]
    public function handle(AdminContext $context): Response
    {
        return $this->setHandled($context, true);
    }

    /** @param AdminContext<Handleable> $context */
    #[AdminRoute('/{entityId}/rouvrir', name: 'reopen', options: ['methods' => ['POST']])]
    public function reopen(AdminContext $context): Response
    {
        return $this->setHandled($context, false);
    }

    /** @param AdminContext<Handleable> $context */
    private function setHandled(AdminContext $context, bool $handled): Response
    {
        $entity = $context->getEntity()->getInstance();
        if (!$entity instanceof Handleable) {
            throw $this->createNotFoundException();
        }
        $entity->setHandled($handled);
        $this->handledEntityManager()->flush();
        $this->addFlash('success', $this->handledNotice($handled));

        // Retour à la liste ou au détail d'où l'on vient, tant que cela reste sur cette origine.
        $request = $context->getRequest();
        $back = SafeRedirect::sameOrigin($request->headers->get('referer'), $request->getSchemeAndHttpHost());

        return $this->redirect($back ?? $this->generateUrl($this->handledIndexRoute()));
    }
}
