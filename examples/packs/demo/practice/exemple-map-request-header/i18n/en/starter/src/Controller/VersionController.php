<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class VersionController
{
    #[Route('/api/version', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $version = $request->headers->get('X-Api-Version');
        if (null === $version) {
            return new Response('Missing X-Api-Version header.', 400);
        }

        return new Response('Requested version: '.$version);
    }
}
