<?php

namespace App\Controller\Api;

use App\Repository\CommandeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class CommandeApiController extends AbstractController
{
    /**
     * FAILLE (chapitre 16, F16.5 — Boss) : la sérialisation suit les relations sans
     * limite. Chaque commande embarque son client ENTIER (mot de passe compris) et
     * ses lignes. Le gestionnaire de référence circulaire évite la boucle infinie
     * mais la sur-exposition reste : c'est tout le catalogue de données clients.
     * Correction : #[Groups] ciblés + #[MaxDepth], ou couper la relation dans le groupe.
     */
    #[Route('/api/commandes', name: 'app_api_commande', methods: ['GET'])]
    public function index(CommandeRepository $commandes): JsonResponse
    {
        return $this->json($commandes->toutes(), context: [
            'circular_reference_handler' => fn (object $o) => method_exists($o, 'getId') ? $o->getId() : null,
        ]);
    }
}
