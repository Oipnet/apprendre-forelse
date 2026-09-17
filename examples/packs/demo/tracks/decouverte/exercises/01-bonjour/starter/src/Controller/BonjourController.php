<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class BonjourController extends AbstractController
{
    // TODO : déclarez la route /bonjour
    public function index(): Response
    {
        return new Response('…');
    }
}
