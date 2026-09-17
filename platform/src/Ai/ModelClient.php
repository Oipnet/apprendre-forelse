<?php

namespace App\Ai;

use App\Content\ContentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Le modèle (API Anthropic, Messages) qui assiste les auteurs et fait office de mentor pour les apprenants.
 *
 * Facultatif : sans clé d'API, tout fonctionne, l'assistance disparaît. La réponse est toujours
 * imposée par un outil, pour n'avoir à lire qu'une structure et jamais du texte libre.
 */
final class ModelClient
{
    private const string URL = 'https://api.anthropic.com/v1/messages';
    private const string VERSION = '2023-06-01';
    /** Une réponse longue (un exercice rédigé en entier) prend plusieurs minutes. */
    private const int TIMEOUT = 300;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'ANTHROPIC_API_KEY')]
        private readonly string $cle = '',
        #[Autowire(env: 'AI_MODEL')]
        private readonly string $modele = 'claude-sonnet-5',
    ) {
    }

    public function disponible(): bool
    {
        return '' !== trim($this->cle);
    }

    /**
     * Demande au modèle d'appeler l'outil, et renvoie ce qu'il lui a passé.
     *
     * @param list<array{role: string, content: string}>                                $messages
     * @param array{name: string, description: string, input_schema: array<mixed>} $outil
     *
     * @return array<mixed> l'entrée de l'outil, telle que le modèle l'a construite
     */
    public function appeler(string $consignes, array $messages, array $outil, int $maxTokens = 16000): array
    {
        if (!$this->disponible()) {
            throw new ContentException('Aucune clé d\'API : la génération est désactivée (voir ANTHROPIC_API_KEY).');
        }

        // PHP coupe une requête web après max_execution_time (30 s par défaut ; sous macOS, en temps réel) :
        // l'appel au modèle dispose du même délai que le client HTTP, plus une marge pour la suite.
        set_time_limit(self::TIMEOUT + 30);
        try {
            $reponse = $this->httpClient->request('POST', self::URL, [
                'headers' => ['x-api-key' => $this->cle, 'anthropic-version' => self::VERSION],
                'json' => [
                    'model' => $this->modele,
                    'max_tokens' => $maxTokens,
                    'system' => $consignes,
                    'tools' => [$outil],
                    'tool_choice' => ['type' => 'tool', 'name' => $outil['name']],
                    'messages' => $messages,
                ],
                'timeout' => self::TIMEOUT,
            ])->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new ContentException('Le modèle n\'a pas répondu : '.$e->getMessage());
        }

        if (isset($reponse['error'])) {
            throw new ContentException(sprintf('Le modèle a refusé : %s', $reponse['error']['message'] ?? 'erreur inconnue'));
        }

        foreach ($reponse['content'] ?? [] as $bloc) {
            if ('tool_use' === ($bloc['type'] ?? null) && \is_array($bloc['input'] ?? null)) {
                return $bloc['input'];
            }
        }

        throw new ContentException(sprintf('Le modèle n\'a pas appelé l\'outil %s.', $outil['name']));
    }
}
