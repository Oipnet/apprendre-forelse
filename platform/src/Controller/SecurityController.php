<?php

namespace App\Controller;

use App\Security\ReturnPath;
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
     * La connexion ramène à la page d'où l'on vient (voir ReturnPath : le Referer, ou `?suite=/chemin`). Chemin local
     * uniquement, comme à l'inscription : pas de redirection ouverte.
     */
    #[Route(['fr' => '/connexion', 'en' => '/en/login'], name: 'app_login')]
    public function login(Request $request, AuthenticationUtils $authenticationUtils, PageSeo $seo, TranslatorInterface $translator, ReturnPath $returnPath): Response
    {
        if ($this->getUser()) {
            return $this->redirect(SafeRedirect::localPath($request->query->getString('suite') ?: null) ?? $this->generateUrl('app_home'));
        }

        // Pas indexée, mais les moteurs la lisent : une description évite l'erreur « description manquante » de Bing.
        $seo->setDescription($translator->trans('compte.connexion', [], 'seo'));

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'suite' => ($suites = $returnPath->forLoginPage($request))['login'],
            'suite_inscription' => $suites['register'],
            // Venue du Referer : un nouveau compte créé par un fournisseur passe d'abord par la démo.
            'demo' => null !== $suites['login'] && '' === $request->query->getString('suite'),
        ]);
    }

    #[Route('/deconnexion', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Intercepté par le firewall (voir security.yaml).');
    }
}
