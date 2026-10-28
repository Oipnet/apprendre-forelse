<?php

namespace App\Controller;

use App\Security\SafeRedirect;
use App\Seo\PageSeo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SecurityController extends AbstractController
{
    /**
     * `?suite=/chemin` : la page d'où l'on vient (un exercice verrouillé, par exemple), où la connexion ramène. Chemin
     * local uniquement, comme à l'inscription : pas de redirection ouverte.
     */
    #[Route(['fr' => '/connexion', 'en' => '/en/login'], name: 'app_login')]
    public function login(Request $request, AuthenticationUtils $authenticationUtils, PageSeo $seo, TranslatorInterface $translator): Response
    {
        $suite = SafeRedirect::localPath($request->query->getString('suite') ?: null);
        if ($this->getUser()) {
            return $this->redirect($suite ?? $this->generateUrl('app_home'));
        }

        // Pas indexée, mais les moteurs la lisent (une adresse par page d'origine, ?suite=) : une description évite
        // l'erreur « description manquante » de Bing.
        $seo->setDescription($translator->trans('compte.connexion', [], 'seo'));

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
