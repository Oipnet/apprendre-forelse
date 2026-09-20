<?php

namespace App\Controller;

use App\Repository\AvisRepository;
use App\Repository\BiereRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CatalogueController extends AbstractController
{
    #[Route('/', name: 'app_accueil', methods: ['GET'])]
    public function accueil(BiereRepository $bieres, AvisRepository $avis): Response
    {
        return $this->render('boutique/accueil.html.twig', [
            'bieres' => \array_slice($bieres->catalogue(), 0, 6),
            // Les 3 avis récents sont affichés avec |raw sur l'accueil : c'est ce
            // qui laisse la bannière de Houblon Noir défigurer la page (F3.1).
            'avis_recents' => $avis->recents(3),
        ]);
    }

    #[Route('/bieres', name: 'app_bieres', methods: ['GET'])]
    public function catalogue(Request $request, BiereRepository $bieres): Response
    {
        $style = $request->query->get('style');

        return $this->render('boutique/catalogue.html.twig', [
            // F2.4 : parStyle() concatène le style dans le QueryBuilder.
            'bieres' => $style ? $bieres->parStyle($style) : $bieres->catalogue(),
            'style' => $style,
        ]);
    }

    #[Route('/bieres/recherche', name: 'app_recherche', methods: ['GET'])]
    public function recherche(Request $request, BiereRepository $bieres): Response
    {
        $q = (string) $request->query->get('q', '');
        $tri = (string) $request->query->get('tri', 'nom');
        $resultats = '' === $q ? [] : $bieres->rechercher($q, $tri);

        return $this->render('boutique/recherche.html.twig', [
            'q' => $q,
            'resultats' => $resultats,
        ]);
    }

    #[Route('/biere/{slug}', name: 'app_biere', methods: ['GET'])]
    public function fiche(string $slug, BiereRepository $bieres, AvisRepository $avis): Response
    {
        $biere = $bieres->findOneBy(['slug' => $slug]);
        if (null === $biere) {
            throw $this->createNotFoundException();
        }

        return $this->render('boutique/fiche.html.twig', [
            'biere' => $biere,
            'avis' => $avis->pourBiere($biere),
        ]);
    }
}
