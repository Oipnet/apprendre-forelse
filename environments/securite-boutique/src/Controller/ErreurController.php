<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ErreurController extends AbstractController
{
    /**
     * FAILLE (chapitre 14, F14.1) : en dev (APP_ENV=dev), cette exception affiche
     * une page de debug avec la requête SQL, un chemin absolu et la pile d'appels.
     * Correction : APP_ENV=prod ET ne jamais mettre de données sensibles dans le message.
     */
    #[Route('/erreur', name: 'app_erreur', methods: ['GET'])]
    public function erreur(): Response
    {
        throw new \RuntimeException(
            'Échec de la requête « SELECT * FROM client WHERE email = ... » '
            .'dans /var/www/html/src/Repository/ClientRepository.php'
        );
    }
}
