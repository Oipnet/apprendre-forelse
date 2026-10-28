<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;

final class AccueilController extends AbstractController
{
    // Edit this code: the preview updates as you type.
    #[Route('/', name: 'accueil')]
    public function index(): Response
    {
        return $this->render('accueil.html.twig', [
            'titre' => 'Hello Symfony!',
            'version' => Kernel::VERSION,
            'idees' => [
                'Add a route with #[Route]',
                'Create a service in src/Service/ and inject it here',
                'Run "debug:router" in the console',
            ],
        ]);
    }

    // Try /bonjour/Ada in the preview's address bar.
    #[Route('/bonjour/{prenom}', name: 'bonjour')]
    public function bonjour(string $prenom): JsonResponse
    {
        return $this->json(['message' => \sprintf('Hello %s!', $prenom)]);
    }
}
