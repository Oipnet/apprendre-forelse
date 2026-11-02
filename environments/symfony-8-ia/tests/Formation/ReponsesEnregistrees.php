<?php

namespace App\Tests\Formation;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Outil des tests d'exercices : le modèle d'IA, sans réseau ni clé.
 *
 * En environnement de test, le client HTTP de Symfony est un MockHttpClient dont cette classe fabrique les réponses
 * (framework.http_client.mock_response_factory, config/packages/ia_tests.yaml). Le test dit ce que le modèle répond
 * (ReponsesDuModele::leModeleRepond()) ; la réponse est écrite au format du fournisseur que l'apprenant a configuré,
 * reconnu à l'hôte appelé : Anthropic, OpenAI, Mistral ou Gemini. Le même test vaut donc quel que soit son choix.
 *
 * Chaque requête partie vers le modèle est retenue : le test vérifie ce que le code de l'apprenant a envoyé (message
 * système, options, outils), puisque c'est la seule chose qu'il maîtrise. Un vrai modèle ne répond jamais deux fois
 * pareil : on ne teste pas sa réponse.
 */
final class ReponsesEnregistrees
{
    public const array FOURNISSEURS = [
        'api.anthropic.com' => 'anthropic',
        'api.openai.com' => 'openai',
        'api.mistral.ai' => 'mistral',
        'generativelanguage.googleapis.com' => 'gemini',
    ];

    /** @var list<Reponse> */
    private static array $aServir = [];

    /** @var list<array{fournisseur: string, methode: string, url: string, corps: array<mixed>}> */
    private static array $requetes = [];

    public static function reinitialiser(): void
    {
        self::$aServir = [];
        self::$requetes = [];
    }

    public static function prevoir(Reponse ...$reponses): void
    {
        array_push(self::$aServir, ...$reponses);
    }

    /** @return list<array{fournisseur: string, methode: string, url: string, corps: array<mixed>, timeout: ?float, max_duration: ?float}> */
    public static function requetes(): array
    {
        return self::$requetes;
    }

    /** @param array<string, mixed> $options */
    public function __invoke(string $methode, string $url, array $options): MockResponse
    {
        $hote = (string) parse_url($url, \PHP_URL_HOST);
        $fournisseur = self::FOURNISSEURS[$hote] ?? null;
        if (null === $fournisseur) {
            return self::erreur(sprintf('Le test ne sait pas répondre pour %s : seuls Anthropic, OpenAI, Mistral et Gemini sont prévus.', $hote));
        }
        $corps = $options['body'] ?? '';
        if (\is_callable($corps)) {
            $morceaux = '';
            while ('' !== ($morceau = $corps(16384))) {
                $morceaux .= $morceau;
            }
            $corps = $morceaux;
        }
        $json = \is_string($corps) && '' !== $corps ? json_decode($corps, true) : null;
        self::$requetes[] = [
            'fournisseur' => $fournisseur, 'methode' => $methode, 'url' => $url, 'corps' => \is_array($json) ? $json : [],
            'timeout' => $options['timeout'] ?? null, 'max_duration' => $options['max_duration'] ?? null,
        ];

        $reponse = array_shift(self::$aServir);
        if (null === $reponse) {
            return self::erreur(sprintf('Votre code appelle le modèle plus souvent que prévu (%d appels) : le test n\'a pas d\'autre réponse.', \count(self::$requetes)));
        }

        if ($reponse->silence) {
            // Une chaîne vide dans le corps d'une MockResponse simule un délai dépassé (le timeout du client).
            return new MockResponse(['']);
        }

        return new MockResponse(json_encode($reponse->auFormat($fournisseur, $url), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE), [
            'http_code' => $reponse->statut,
            'response_headers' => ['content-type' => 'application/json', ...$reponse->entetes],
        ]);
    }

    private static function erreur(string $message): MockResponse
    {
        return new MockResponse(json_encode(['error' => ['type' => 'invalid_request_error', 'message' => $message]], \JSON_UNESCAPED_UNICODE), [
            'http_code' => 400,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }
}
