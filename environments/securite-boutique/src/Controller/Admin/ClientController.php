<?php

namespace App\Controller\Admin;

use App\Repository\ClientRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * FAILLE (chapitre 8, F8.2/F8.3) : pas de contrôle d'accès côté serveur. Le menu
 * masque le lien avec is_granted('ROLE_ADMINISTRATEUR') — un rôle qui n'existe
 * nulle part (F8.3) : le lien est caché à tout le monde, mais l'URL reste ouverte.
 * Il n'y a par ailleurs aucune role_hierarchy. La page liste des données sensibles.
 */
class ClientController extends AbstractController
{
    #[Route('/admin/clients', name: 'app_admin_clients', methods: ['GET'])]
    public function index(ClientRepository $clients): Response
    {
        return $this->render('admin/clients.html.twig', ['clients' => $clients->findAll()]);
    }
}
