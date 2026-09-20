<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// F8.2 : pas de contrôle d'accès.
class ImportController extends AbstractController
{
    /**
     * Import du catalogue d'un fournisseur par URL.
     *
     * FAILLE (chapitre 15, F15.1 — SSRF) : l'URL vient de la requête, sans aucune
     * liste d'autorisation, et le corps de la réponse est réaffiché dans la page.
     *   /admin/import?url=http://metadata.interne/metadata
     * atteint le faux réseau interne de l'hébergeur (service Docker « faux-reseau »).
     * Correction : liste d'hôtes autorisés, schéma https, max_redirects: 0, et
     * revérifier après chaque redirection.
     */
    #[Route('/admin/import', name: 'app_admin_import', methods: ['GET', 'POST'])]
    public function importer(Request $request, HttpClientInterface $http): Response
    {
        $url = (string) $request->query->get('url', '');
        $corps = null;
        $erreur = null;

        if ('' !== $url) {
            try {
                $corps = $http->request('GET', $url)->getContent(false);
            } catch (\Throwable $e) {
                $erreur = $e->getMessage();
            }
        }

        return $this->render('admin/import.html.twig', [
            'url' => $url,
            'corps' => $corps,
            'erreur' => $erreur,
        ]);
    }

    /**
     * FAILLE (chapitre 15, F15.3 — Boss) : l'hôte est vérifié AVANT de suivre la
     * redirection. Un domaine autorisé qui redirige vers http://metadata.interne/
     * passe le contrôle. Correction : revérifier à chaque saut, ou max_redirects: 0.
     */
    #[Route('/admin/webhook', name: 'app_admin_webhook', methods: ['GET'])]
    public function webhook(Request $request, HttpClientInterface $http): Response
    {
        $url = (string) $request->query->get('url', '');
        $autorises = ['catalogue.fournisseur-a.example', 'exports.fournisseur-b.example'];
        $hote = parse_url($url, PHP_URL_HOST);

        if (!\in_array($hote, $autorises, true)) {
            return new Response('Hôte non autorisé : '.$hote, 403);
        }

        // Le contrôle est passé ; on suit ensuite les redirections sans revérifier.
        $corps = $http->request('GET', $url, ['max_redirects' => 5])->getContent(false);

        return new Response($corps, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
