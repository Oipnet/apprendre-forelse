<?php

namespace App\Controller;

use App\Seo\SearchIndexing;
use App\Seo\Sitemap;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** robots.txt et sitemap.xml, servis par l'application : ils dépendent de l'origine et de l'instance. */
final class SeoController extends AbstractController
{
    /**
     * Servi aussi sur le bac à sable (voir OriginIsolationListener), qui y interdit tout.
     * Les pages de connexion ou d'inscription ne sont pas bloquées ici : un robot doit pouvoir y lire leur noindex.
     */
    #[Route('/robots.txt', name: 'app_robots', methods: ['GET'], format: 'txt')]
    public function robots(Request $request, SearchIndexing $indexing): Response
    {
        $lines = ['User-agent: *'];
        if (!$indexing->isEnabled($request)) {
            $lines[] = 'Disallow: /';
        } else {
            array_push(
                $lines,
                'Disallow: /admin',
                'Disallow: /cohorte',
                'Disallow: /atelier',
                'Disallow: /compte',
                'Disallow: /achat/',
                'Disallow: /paiement/',
                'Disallow: /api/',
                // Liens d'invitation : un code de cohorte ne doit pas circuler par un moteur de recherche.
                'Disallow: /*?code=',
                'Disallow: /*&code=',
                '',
                'Sitemap: '.$this->generateUrl('app_sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL),
            );
        }

        return $this->cached(new Response(implode("\n", $lines)."\n", headers: ['Content-Type' => 'text/plain; charset=UTF-8']));
    }

    #[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'], format: 'xml')]
    public function sitemap(Sitemap $sitemap): Response
    {
        $response = $this->render('seo/sitemap.xml.twig', ['entries' => $sitemap->entries()]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return $this->cached($response);
    }

    /** Identiques pour tous les visiteurs : une heure de cache partagé. */
    private function cached(Response $response): Response
    {
        return $response->setPublic()->setMaxAge(3600);
    }
}
