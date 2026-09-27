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
    #[Seo('Auto-héberger la plateforme, moteur open source', 'Le moteur de %marque% est libre (AGPL-3.0) : installez la plateforme sur votre serveur avec Docker, écrivez vos parcours, ou utilisez les siens sur devis.', breadcrumb: 'Auto-hébergement')]
    public function __invoke(SelfHostingPage $page): Response
    {
        if (!$page->enabled) {
            throw $this->createNotFoundException();
        }
        return $this->render('self_hosting/index.html.twig', ['page' => $page]);
    }
}
