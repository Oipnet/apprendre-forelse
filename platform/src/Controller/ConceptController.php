<?php

namespace App\Controller;

use App\Content\ConceptIndex;
use App\Content\Exercise;
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
 *
 * Sous /en/concepts, les notions du contenu traduit en anglais, sous leurs libellés anglais (voir ConceptIndex).
 */
final class ConceptController extends AbstractController
{
    #[Route(['fr' => '/notions', 'en' => '/en/concepts'], name: 'app_concepts', methods: ['GET'])]
    public function index(Request $request, ConceptIndex $concepts, ConceptSeo $seo): Response
    {
        $locale = self::locale($request);
        $seo->index($concepts->all($locale));
        $catalog = $concepts->catalog($locale);
        // Une langue sans notion encore (rien de traduit) n'a qu'une page vide : elle ne s'indexe pas.
        if ([] === $catalog && Exercise::LOCALE !== $locale) {
            SearchIndexing::markThin($request);
        }

        return $this->render('concept/index.html.twig', ['catalog' => $catalog]);
    }

    #[Route(['fr' => '/notions/{slug}', 'en' => '/en/concepts/{slug}'], name: 'app_concept', methods: ['GET'])]
    public function show(string $slug, Request $request, ConceptIndex $concepts, ConceptSeo $seo, LessonRenderer $markdown): Response
    {
        $locale = self::locale($request);
        $concept = $concepts->find($slug, $locale) ?? throw $this->createNotFoundException();
        $seo->concept($concept);
        // Sans intro écrite, la page n'est qu'une liste de liens : elle se suit, mais ne s'indexe pas.
        if (null === $concept['intro']) {
            SearchIndexing::markThin($request);
        }

        return $this->render('concept/show.html.twig', [...$concept, 'introHtml' => null === $concept['intro'] ? null : $markdown->toHtml($concept['intro'])]);
    }

    /** La langue de la page ; une variante régionale (en_GB) compte pour sa langue. */
    private static function locale(Request $request): string
    {
        return explode('_', $request->getLocale())[0];
    }
}
