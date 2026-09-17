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
}
