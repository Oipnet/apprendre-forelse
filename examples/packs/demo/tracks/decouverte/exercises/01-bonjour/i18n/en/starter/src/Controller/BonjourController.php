<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class BonjourController extends AbstractController
{
    // TODO: declare the /hello route
    public function index(): Response
    {
        return new Response('…');
    }
}
