<?php

namespace App\Controller\Admin;

use App\Repository\BiereRepository;
use App\Repository\ClientRepository;
use App\Repository\CommandeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * FAILLE (chapitre 8, F8.2) : AUCUN #[IsGranted] ici, et aucune règle access_control
 * pour ^/admin. Seul le menu cache les liens. /admin répond à un visiteur anonyme.
 */
class TableauDeBordController extends AbstractController
{
    #[Route('/admin', name: 'app_admin', methods: ['GET'])]
    public function index(BiereRepository $bieres, CommandeRepository $commandes, ClientRepository $clients): Response
    {
        return $this->render('admin/index.html.twig', [
            'nb_bieres' => \count($bieres->findAll()),
            'nb_commandes' => \count($commandes->findAll()),
            'nb_clients' => \count($clients->findAll()),
        ]);
    }
}
