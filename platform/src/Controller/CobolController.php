<?php

namespace App\Controller;

use App\Seo\Seo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * L'atelier COBOL : un générateur de programmes batch COBOL à l'ancienne (rubriques, enregistrement, langage
 * structuré, base façon DB2), qui génère le source puis l'exécute dans le navigateur (playground/src/cobol).
 * Le serveur ne fait que servir les pages (atelier, terminal, mémo) : le programme reste dans le navigateur du visiteur, et un lien
 * partagé le porte après le # (rien n'est envoyé ni gardé ici). En français et en anglais : la page porte sa langue
 * (<html lang>), que le script suit (playground/src/cobol/langue.js) ; le mémo a un gabarit par langue.
 */
final class CobolController extends AbstractController
{
    #[Route(['fr' => '/atelier-cobol', 'en' => '/en/cobol-workshop'], name: 'app_cobol', methods: ['GET'])]
    #[Seo('atelier_cobol.atelier.titre', 'atelier_cobol.atelier.description', breadcrumb: 'atelier_cobol.atelier.fil_ariane', domain: 'seo')]
    public function workshop(): Response
    {
        return $this->render('atelier_cobol/atelier.html.twig');
    }

    #[Route(['fr' => '/atelier-cobol/terminal', 'en' => '/en/cobol-workshop/terminal'], name: 'app_cobol_terminal', methods: ['GET'])]
    #[Seo('atelier_cobol.terminal.titre', 'atelier_cobol.terminal.description', breadcrumb: 'atelier_cobol.terminal.fil_ariane', domain: 'seo')]
    public function terminal(): Response
    {
        return $this->render('atelier_cobol/terminal.html.twig');
    }

    #[Route(['fr' => '/atelier-cobol/memo', 'en' => '/en/cobol-workshop/memo'], name: 'app_cobol_memo', methods: ['GET'])]
    #[Seo('atelier_cobol.memo.titre', 'atelier_cobol.memo.description', breadcrumb: 'atelier_cobol.memo.fil_ariane', domain: 'seo')]
    public function memo(Request $request): Response
    {
        return $this->render('en' === $request->getLocale() ? 'atelier_cobol/memo.en.html.twig' : 'atelier_cobol/memo.html.twig');
    }
}
