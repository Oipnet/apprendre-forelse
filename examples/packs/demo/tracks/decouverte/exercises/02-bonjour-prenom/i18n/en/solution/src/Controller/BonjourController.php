<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BonjourController extends AbstractController
{
    #[Route('/hello', name: 'app_hello')]
    public function index(): Response
    {
        return new Response('Hello Symfony!');
    }

    #[Route('/hello/{name}', name: 'app_hello_name')]
    public function name(string $name): Response
    {
        return new Response(sprintf('Hello %s!', $name));
    }
}
