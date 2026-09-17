<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BonjourController extends AbstractController
{
    #[Route('/bonjour', name: 'app_bonjour')]
    public function index(): Response
    {
        return new Response('Bonjour Symfony !');
    }

    #[Route('/bonjour/{prenom}', name: 'app_bonjour_prenom')]
    public function prenom(string $prenom): Response
    {
        return new Response(sprintf('Bonjour %s !', $prenom));
    }
}
