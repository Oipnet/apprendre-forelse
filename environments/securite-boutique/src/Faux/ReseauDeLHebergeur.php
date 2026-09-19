<?php

namespace App\Faux;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Le « réseau » vu par la boutique : aucun accès réseau réel dans le bac à sable.
 * Ce MockHttpClient simule le réseau interne de l'hébergeur, cible du SSRF (chapitre 15).
 * La requête part vraiment (le code est identique à une vraie), mais la cible est fictive.
 */
final class ReseauDeLHebergeur
{
    public function client(): HttpClientInterface
    {
        return new MockHttpClient(function (string $method, string $url): MockResponse {
            $hote = parse_url($url, PHP_URL_HOST) ?? '';
            $chemin = parse_url($url, PHP_URL_PATH) ?? '/';

            // Le point de métadonnées de l'infrastructure interne (jeton FICTIF).
            if (\in_array($hote, ['10.0.0.7', 'metadata.interne'], true) && str_contains($chemin, 'metadata')) {
                return new MockResponse(
                    '{"role":"lacombe-prod-deploy","token":"AKIA-FICTIF-NE-FONCTIONNE-PAS-0000"}',
                    ['response_headers' => ['content-type' => 'application/json']],
                );
            }
            // Un « fournisseur autorisé » qui redirige vers l'interne (Boss ch15, F15.3).
            if ('catalogue.fournisseur-a.example' === $hote && str_contains($chemin, 'redirect')) {
                return new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'http://10.0.0.7/metadata']]);
            }
            // Un vrai catalogue fournisseur (usage légitime).
            if (str_ends_with($hote, '.fournisseur-a.example') || str_ends_with($hote, '.fournisseur-b.example')) {
                return new MockResponse("slug,nom,prix\nblonde-test,Blonde de test,4.20\n", ['response_headers' => ['content-type' => 'text/csv']]);
            }

            return new MockResponse('Hôte injoignable (réseau simulé).', ['http_code' => 502]);
        });
    }
}
