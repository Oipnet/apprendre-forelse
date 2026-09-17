<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestHeader;
use Symfony\Component\Routing\Attribute\Route;

class VersionController
{
    #[Route('/api/version', methods: ['GET'])]
    public function __invoke(#[MapRequestHeader] string $xApiVersion): Response
    {
        return new Response('Version demandée : '.$xApiVersion);
    }
}
