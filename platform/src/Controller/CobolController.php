<?php

namespace App\Controller;

use App\Seo\Seo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * L'atelier COBOL : un générateur de programmes batch COBOL à l'ancienne (rubriques, enregistrement, langage
 * structuré, base façon DB2), qui génère le source puis l'exécute dans le navigateur (playground/src/cobol).
 * Le serveur ne fait que servir les deux pages : le programme reste dans le navigateur du visiteur, et un lien
 * partagé le porte après le # (rien n'est envoyé ni gardé ici). En français seulement.
 */
final class CobolController extends AbstractController
{
    #[Route('/atelier-cobol', name: 'app_cobol', methods: ['GET'])]
    #[Seo('atelier_cobol.atelier.titre', 'atelier_cobol.atelier.description', breadcrumb: 'atelier_cobol.atelier.fil_ariane', domain: 'seo')]
    public function workshop(): Response
    {
        return $this->render('atelier_cobol/atelier.html.twig');
    }

    #[Route('/atelier-cobol/terminal', name: 'app_cobol_terminal', methods: ['GET'])]
    #[Seo('atelier_cobol.terminal.titre', 'atelier_cobol.terminal.description', breadcrumb: 'atelier_cobol.terminal.fil_ariane', domain: 'seo')]
    public function terminal(): Response
    {
        return $this->render('atelier_cobol/terminal.html.twig');
    }
}
