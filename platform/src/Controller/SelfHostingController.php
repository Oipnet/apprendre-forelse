<?php

namespace App\Controller;

use App\Instance\SelfHostingPage;
use App\Seo\SeoWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SelfHostingController extends AbstractController
{
    #[Route('/auto-hebergement', name: 'app_self_hosting', methods: ['GET'])]
    public function __invoke(SelfHostingPage $page, SeoWriter $seo): Response
    {
        if (!$page->enabled) {
            throw $this->createNotFoundException();
        }
        $seo->selfHosting();

        return $this->render('self_hosting/index.html.twig', ['page' => $page]);
    }
}
