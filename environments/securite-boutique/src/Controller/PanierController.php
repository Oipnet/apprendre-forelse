<?php

namespace App\Controller;

use App\Repository\BiereRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PanierController extends AbstractController
{
    #[Route('/panier', name: 'app_panier', methods: ['GET'])]
    public function index(Request $request, BiereRepository $bieres): Response
    {
        $panier = $request->getSession()->get('panier', []);
        $lignes = [];
        $total = 0.0;
        foreach ($panier as $id => $quantite) {
            $biere = $bieres->find($id);
            if (null === $biere) {
                continue;
            }
            $sousTotal = (float) $biere->getPrix() * $quantite;
            $total += $sousTotal;
            $lignes[] = ['biere' => $biere, 'quantite' => $quantite, 'sousTotal' => $sousTotal];
        }

        return $this->render('boutique/panier.html.twig', ['lignes' => $lignes, 'total' => $total]);
    }

    /**
     * FAILLE (chapitre 4, F4.1) : ajout au panier en POST sans jeton CSRF.
     */
    #[Route('/panier/ajouter', name: 'app_panier_ajouter', methods: ['POST'])]
    public function ajouter(Request $request): Response
    {
        $session = $request->getSession();
        $panier = $session->get('panier', []);
        $id = (int) $request->request->get('biere');
        $quantite = max(1, (int) $request->request->get('quantite', 1));
        $panier[$id] = ($panier[$id] ?? 0) + $quantite;
        $session->set('panier', $panier);

        return $this->redirectToRoute('app_panier');
    }

    #[Route('/panier/retirer', name: 'app_panier_retirer', methods: ['POST'])]
    public function retirer(Request $request): Response
    {
        $session = $request->getSession();
        $panier = $session->get('panier', []);
        unset($panier[(int) $request->request->get('biere')]);
        $session->set('panier', $panier);

        return $this->redirectToRoute('app_panier');
    }
}
