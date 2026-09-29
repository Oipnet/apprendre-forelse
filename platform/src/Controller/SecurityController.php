<?php

namespace App\Controller;

use App\Security\SafeRedirect;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    /**
     * `?suite=/chemin` : la page d'où l'on vient (un exercice verrouillé, par exemple), où la connexion ramène. Chemin
     * local uniquement, comme à l'inscription : pas de redirection ouverte.
     */
    #[Route('/connexion', name: 'app_login')]
    public function login(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        $suite = SafeRedirect::localPath($request->query->getString('suite') ?: null);
        if ($this->getUser()) {
            return $this->redirect($suite ?? $this->generateUrl('app_home'));
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'suite' => $suite,
        ]);
    }

    #[Route('/deconnexion', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Intercepté par le firewall (voir security.yaml).');
    }
}
