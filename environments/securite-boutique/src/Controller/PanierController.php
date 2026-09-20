<?php

namespace App\Controller;

use App\Repository\BiereRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le panier, gardé en session (slug => quantité). Écrit par le prestataire : les actions
 * qui modifient le panier acceptent n'importe quel POST, sans vérifier de jeton CSRF.
 */
class PanierController extends AbstractController
{
    #[Route('/panier', name: 'app_panier', methods: ['GET'])]
    public function index(Request $request, BiereRepository $bieres): Response
    {
        $panier = $request->getSession()->get('panier', []);
        $lignes = [];
        $total = 0.0;
        foreach ($panier as $slug => $quantite) {
            $biere = $bieres->findOneBy(['slug' => $slug]);
            if (null === $biere) {
                continue;
            }
            $sousTotal = (float) $biere->getPrix() * $quantite;
            $total += $sousTotal;
            $lignes[] = ['biere' => $biere, 'quantite' => $quantite, 'sousTotal' => $sousTotal];
        }

        return $this->render('boutique/panier.html.twig', ['lignes' => $lignes, 'total' => $total]);
    }

    /** FAILLE (chapitre 4, F4.1) : ajout au panier en POST sans jeton CSRF. */
    #[Route('/panier/ajouter', name: 'app_panier_ajouter', methods: ['POST'])]
    public function ajouter(Request $request, BiereRepository $bieres): Response
    {
        $slug = (string) $request->request->get('slug');
        $quantite = max(1, (int) $request->request->get('quantite', 1));

        if ($bieres->findOneBy(['slug' => $slug])) {
            $panier = $request->getSession()->get('panier', []);
            $panier[$slug] = ($panier[$slug] ?? 0) + $quantite;
            $request->getSession()->set('panier', $panier);
        }

        return $this->redirectToRoute('app_panier');
    }

    /** FAILLE (chapitre 4, F4.1) : retrait sans jeton CSRF. */
    #[Route('/panier/retirer', name: 'app_panier_retirer', methods: ['POST'])]
    public function retirer(Request $request): Response
    {
        $panier = $request->getSession()->get('panier', []);
        unset($panier[(string) $request->request->get('slug')]);
        $request->getSession()->set('panier', $panier);

        return $this->redirectToRoute('app_panier');
    }
}
