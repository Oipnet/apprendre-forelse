<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/connexion', name: 'app_connexion', methods: ['GET', 'POST'])]
    public function connexion(Request $request, AuthenticationUtils $utils): Response
    {
        // La page de connexion établit une session (jeton CSRF du formulaire) : un cookie
        // de session est donc posé — avec, ou sans, les attributs de sécurité (chapitre 9).
        $session = $request->getSession();
        $session->set('_visites_connexion', $session->get('_visites_connexion', 0) + 1);

        return $this->render('securite/connexion.html.twig', [
            'dernier_identifiant' => $utils->getLastUsername(),
            'erreur' => $utils->getLastAuthenticationError(),
        ]);
    }

    /**
     * FAILLE (chapitre 9, F9.3) : la déconnexion se contente de vider le panier
     * et d'oublier le jeton en mémoire ; la session n'est PAS invalidée, et le
     * cookie « souvenir » n'est pas effacé (il reconnecte à la requête suivante).
     */
    #[Route('/deconnexion', name: 'app_deconnexion', methods: ['GET'])]
    public function deconnexion(Request $request, TokenStorageInterface $tokenStorage): Response
    {
        $request->getSession()->remove('panier');
        $tokenStorage->setToken(null);

        return $this->redirectToRoute('app_accueil');
    }
}
