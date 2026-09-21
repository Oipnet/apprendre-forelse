<?php

namespace App\Controller;

use App\Content\ConceptIndex;
use App\Seo\SeoWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Les notions du contenu : l'index, et une page par notion. Elles relient entre eux des exercices que rien
 * d'autre ne rapproche — un exercice du chapitre 3 et un exercice de Pratique paru six mois plus tard.
 */
final class ConceptController extends AbstractController
{
    #[Route('/notions', name: 'app_concepts', methods: ['GET'])]
    public function index(ConceptIndex $concepts, SeoWriter $seo): Response
    {
        $all = $concepts->all();
        $seo->conceptList($all);

        return $this->render('concept/index.html.twig', ['concepts' => $all]);
    }

    #[Route('/notions/{slug}', name: 'app_concept', methods: ['GET'])]
    public function show(string $slug, ConceptIndex $concepts, SeoWriter $seo): Response
    {
        $concept = $concepts->find($slug) ?? throw $this->createNotFoundException();
        $seo->concept($concept);

        return $this->render('concept/show.html.twig', $concept);
    }
}
