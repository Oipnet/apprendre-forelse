<?php

namespace App\Controller;

use App\Instance\SelfHostingPage;
use App\Seo\Seo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SelfHostingController extends AbstractController
{
    #[Route('/auto-hebergement', name: 'app_self_hosting', methods: ['GET'])]
    #[Seo('auto_hebergement.titre', 'auto_hebergement.description', breadcrumb: 'auto_hebergement.fil_ariane', domain: 'seo')]
    public function __invoke(SelfHostingPage $page): Response
    {
        // Une page que seul un thème rédige : sans elle, rien à servir.
        if (!$page->enabled()) {
            throw $this->createNotFoundException();
        }

        return $this->render(SelfHostingPage::TEMPLATE, ['page' => $page]);
    }
}
