<?php

namespace App\Controller;

use App\Content\ConceptIndex;
use App\Content\LessonRenderer;
use App\Seo\Page\ConceptSeo;
use App\Seo\SearchIndexing;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Les notions du contenu : l'index, et une page par notion. Elles relient entre eux des exercices que rien
 * d'autre ne rapproche — un exercice du chapitre 3 et un exercice de Pratique paru six mois plus tard.
 */
final class ConceptController extends AbstractController
{
    #[Route('/notions', name: 'app_concepts', methods: ['GET'])]
    public function index(ConceptIndex $concepts, ConceptSeo $seo): Response
    {
        $all = $concepts->all();
        $seo->index($all);

        return $this->render('concept/index.html.twig', ['concepts' => $all]);
    }

    #[Route('/notions/{slug}', name: 'app_concept', methods: ['GET'])]
    public function show(string $slug, Request $request, ConceptIndex $concepts, ConceptSeo $seo, LessonRenderer $markdown): Response
    {
        $concept = $concepts->find($slug) ?? throw $this->createNotFoundException();
        $seo->concept($concept);
        // Sans intro écrite, la page n'est qu'une liste de liens : elle se suit, mais ne s'indexe pas.
        if (null === $concept['intro']) {
            SearchIndexing::markThin($request);
        }

        return $this->render('concept/show.html.twig', [...$concept, 'introHtml' => null === $concept['intro'] ? null : $markdown->toHtml($concept['intro'])]);
    }
}
