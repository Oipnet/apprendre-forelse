<?php

namespace App\Controller;

use App\Repository\ClientRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class ConnexionRapideController extends AbstractController
{
    /**
     * FAILLE (chapitre 7, F7.5 — Boss) : porte dérobée laissée par le prestataire.
     * Hors access_control, elle connecte en admin si le jeton vaut une constante en dur.
     */
    #[Route('/connexion-rapide', name: 'app_connexion_rapide', methods: ['GET'])]
    public function connexionRapide(Request $request, ClientRepository $clients, TokenStorageInterface $tokenStorage): Response
    {
        if ('damien2024' !== $request->query->get('jeton')) {
            throw $this->createNotFoundException();
        }
        $marc = $clients->parEmail('marc@brasserie-lacombe.fr');
        if (null !== $marc) {
            $tokenStorage->setToken(new UsernamePasswordToken($marc, 'main', $marc->getRoles()));
        }

        return $this->redirectToRoute('app_admin');
    }
}
