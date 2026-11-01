<?php

namespace App\Controller;

use App\Search\SearchDocument;
use App\Search\SearchUnavailable;
use App\Search\SiteSearch;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La recherche du site (voir App\Search) : exercices de Pratique, articles, notions, parcours, dans la langue de la page.
 * ?q= les mots cherchés, ?type= un onglet (SearchDocument::TYPES). Absente sans Meilisearch (MEILISEARCH_URL vide).
 *
 * Une page de résultats ne s'indexe pas : elle n'est pas dans App\Seo\SearchIndexing::ROUTES, et robots.txt l'écarte.
 */
final class SearchController extends AbstractController
{
    #[Route(['fr' => '/recherche', 'en' => '/en/search'], name: 'app_search', methods: ['GET'])]
    public function index(
        Request $request,
        SiteSearch $search,
        LoggerInterface $logger,
        #[MapQueryParameter] string $q = '',
        #[MapQueryParameter] ?string $type = null,
    ): Response {
        if (!$search->isEnabled()) {
            throw $this->createNotFoundException();
        }
        $query = mb_substr(trim($q), 0, SiteSearch::QUERY_MAX_LENGTH);
        $type = \in_array($type, SearchDocument::TYPES, true) ? $type : null;

        $results = null;
        $unavailable = false;
        if ('' !== $query) {
            try {
                $results = $search->search($query, explode('_', $request->getLocale())[0], $type);
            } catch (SearchUnavailable $exception) {
                $logger->warning($exception->getMessage());
                $unavailable = true;
            }
        }

        return $this->render('search/index.html.twig', [
            'query' => $query,
            'type' => $type,
            'results' => $results,
            'unavailable' => $unavailable,
        ], new Response(status: $unavailable ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK));
    }
}
