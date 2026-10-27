<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;

final class AccueilController extends AbstractController
{
    // Modifiez ce code : l'aperçu se met à jour à chaque frappe.
    #[Route('/', name: 'accueil')]
    public function index(): Response
    {
        return $this->render('accueil.html.twig', [
            'titre' => 'Bonjour Symfony !',
            'version' => Kernel::VERSION,
            'idees' => [
                'Ajouter une route avec #[Route]',
                'Créer un service dans src/Service/ et l\'injecter ici',
                'Lancer « debug:router » dans la console',
            ],
        ]);
    }

    // Essayez /bonjour/Ada dans la barre d'adresse de l'aperçu.
    #[Route('/bonjour/{prenom}', name: 'bonjour')]
    public function bonjour(string $prenom): JsonResponse
    {
        return $this->json(['message' => \sprintf('Bonjour %s !', $prenom)]);
    }
}
