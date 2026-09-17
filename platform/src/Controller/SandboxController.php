<?php

namespace App\Controller;

use App\Security\SandboxOrigin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Relais d'aperçu, servi uniquement sur l'origine bac à sable (voir OriginIsolationListener). */
final class SandboxController extends AbstractController
{
    #[Route(SandboxOrigin::RELAY_PATH, name: 'app_sandbox', methods: ['GET'])]
    public function relay(SandboxOrigin $sandbox): Response
    {
        return $this->render('sandbox.html.twig', ['host_origin' => $sandbox->platformOrigin]);
    }
}
