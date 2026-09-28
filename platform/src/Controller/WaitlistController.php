<?php

namespace App\Controller;

use App\Account\Waitlist;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liste d'attente de la page d'accueil (bêta fermée) : une adresse, rien d'autre.
 * Le résultat est rendu par la page d'accueil elle-même (?liste=ok|invalide), pas par un flash
 * qui s'afficherait en haut de page, loin du formulaire.
 */
final class WaitlistController extends AbstractController
{
    #[Route('/liste-d-attente', name: 'app_waitlist', methods: ['POST'])]
    public function join(Request $request, Waitlist $waitlist): Response
    {
        // 403 direct : l'exception « accès refusé » de la sécurité enverrait l'invité vers la page de connexion.
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }

        // Champ caché que seuls les robots remplissent : on fait comme si tout allait bien, sans rien enregistrer.
        if ('' !== $request->request->getString('site')) {
            return $this->backToForm('ok');
        }

        return $this->backToForm($waitlist->join($request->request->getString('email')) ? 'ok' : 'invalide');
    }

    private function backToForm(string $state): Response
    {
        return $this->redirect($this->generateUrl('app_home', ['liste' => $state]).'#liste-attente', Response::HTTP_SEE_OTHER);
    }
}
