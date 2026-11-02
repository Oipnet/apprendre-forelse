<?php

namespace App\Tests\Formation;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Outil des tests d'exercices du parcours IA : dire ce que le modèle répond, puis lire ce que le code lui a envoyé.
 *
 *     $this->leModeleRepond(Reponse::texte('Bonjour !'));
 *     $client->request('GET', '/assistant?question=Bonjour');
 *     $this->assertStringContainsString('thé', $this->messageSysteme());
 *
 * Les requêtes sont lues de la même façon quel que soit le fournisseur configuré (Anthropic, OpenAI, Mistral, Gemini) :
 * chacun range le message système, l'historique et les options à sa manière.
 */
trait ReponsesDuModele
{
    #[Before]
    protected function oublierLesReponsesDuModele(): void
    {
        ReponsesEnregistrees::reinitialiser();
    }

    #[After]
    protected function viderLesReponsesDuModele(): void
    {
        ReponsesEnregistrees::reinitialiser();
    }

    protected function leModeleRepond(Reponse|string ...$reponses): void
    {
        ReponsesEnregistrees::prevoir(...array_map(static fn (Reponse|string $r): Reponse => \is_string($r) ? Reponse::texte($r) : $r, $reponses));
    }

    /**
     * Les appels au modèle de langage (conversation, outils, sortie structurée), sans les calculs d'embeddings.
     *
     * @return list<array{fournisseur: string, methode: string, url: string, corps: array<mixed>}>
     */
    protected function appelsAuModele(): array
    {
        return array_values(array_filter(ReponsesEnregistrees::requetes(), static fn (array $r): bool => !self::estUnEmbedding($r['url'])));
    }

    /**
     * Les appels qui calculent des embeddings (chapitre RAG).
     *
     * @return list<array{fournisseur: string, methode: string, url: string, corps: array<mixed>}>
     */
    protected function appelsDEmbeddings(): array
    {
        return array_values(array_filter(ReponsesEnregistrees::requetes(), static fn (array $r): bool => self::estUnEmbedding($r['url'])));
    }

    /** @return list<string> les textes envoyés pour calcul d'embeddings, dans l'ordre, tous appels confondus */
    protected function textesVectorises(): array
    {
        $textes = [];
        foreach ($this->appelsDEmbeddings() as $appel) {
            $corps = $appel['corps'];
            if (isset($corps['input'])) {
                array_push($textes, ...array_map('strval', (array) $corps['input']));
            }
            foreach ($corps['requests'] ?? (isset($corps['content']) ? [$corps] : []) as $requete) {
                $textes[] = self::texteDe($requete['content']['parts'] ?? []);
            }
        }

        return $textes;
    }

    /** @return array{fournisseur: string, methode: string, url: string, corps: array<mixed>} */
    protected function dernierAppelAuModele(): array
    {
        $appels = $this->appelsAuModele();
        static::assertNotEmpty($appels, 'Votre code n\'a pas appelé le modèle.');

        return $appels[array_key_last($appels)];
    }

    /** Le message système du dernier appel, '' s'il n'y en a pas. */
    protected function messageSysteme(): string
    {
        $appel = $this->dernierAppelAuModele();
        $corps = $appel['corps'];

        return match ($appel['fournisseur']) {
            'anthropic' => self::texteDe($corps['system'] ?? ''),
            'openai' => self::texteDe($corps['instructions'] ?? ''),
            'gemini' => self::texteDe($corps['system_instruction']['parts'] ?? []),
            default => implode("\n", array_map(static fn (array $m): string => self::texteDe($m['content'] ?? ''), array_filter($corps['messages'] ?? [], static fn (array $m): bool => 'system' === ($m['role'] ?? null)))),
        };
    }

    /**
     * La conversation envoyée au dernier appel, sans le message système ni les outils : rôle (« user » ou « assistant »)
     * et texte.
     *
     * @return list<array{role: string, texte: string}>
     */
    protected function conversation(): array
    {
        $appel = $this->dernierAppelAuModele();
        $corps = $appel['corps'];
        $messages = match ($appel['fournisseur']) {
            'openai' => $corps['input'] ?? [],
            'gemini' => $corps['contents'] ?? [],
            default => $corps['messages'] ?? [],
        };
        $conversation = [];
        foreach ($messages as $message) {
            $role = match ($message['role'] ?? '') {
                'model' => 'assistant',
                default => (string) ($message['role'] ?? ''),
            };
            $texte = self::texteDe($message['content'] ?? $message['parts'] ?? '');
            // Le message système, les appels d'outils et leurs résultats ne sont pas la conversation : voir resultatsDOutils().
            if (!\in_array($role, ['user', 'assistant'], true) || '' === $texte) {
                continue;
            }
            $conversation[] = ['role' => $role, 'texte' => $texte];
        }

        return $conversation;
    }

    /** Le dernier message de l'utilisateur envoyé au modèle. */
    protected function questionEnvoyee(): string
    {
        $questions = array_filter($this->conversation(), static fn (array $m): bool => 'user' === $m['role']);
        static::assertNotEmpty($questions, 'Aucun message utilisateur n\'est parti vers le modèle.');

        return $questions[array_key_last($questions)]['texte'];
    }

    /** Une option du dernier appel (temperature, max_tokens…), là où le fournisseur la range ; null si absente. */
    protected function optionEnvoyee(string $nom): mixed
    {
        $corps = $this->dernierAppelAuModele()['corps'];

        return $corps[$nom] ?? $corps['generationConfig'][$nom] ?? null;
    }

    /**
     * La limite de jetons de réponse du dernier appel, quel que soit le nom que lui donne le fournisseur : max_tokens
     * (Anthropic, Mistral), max_output_tokens (OpenAI) ou maxOutputTokens (Gemini). Null si absente.
     */
    protected function limiteDeJetons(): ?int
    {
        foreach (['max_tokens', 'max_output_tokens', 'maxOutputTokens'] as $nom) {
            $valeur = $this->optionEnvoyee($nom);
            if (null !== $valeur) {
                return (int) $valeur;
            }
        }

        return null;
    }

    /**
     * Le schéma JSON de sortie structurée demandé au dernier appel (option response_format), là où le fournisseur le
     * range ; null si l'appel n'en demande pas.
     *
     * @return array<string, mixed>|null
     */
    protected function schemaDemande(): ?array
    {
        $appel = $this->dernierAppelAuModele();
        $corps = $appel['corps'];

        $schema = match ($appel['fournisseur']) {
            'anthropic' => $corps['output_config']['format']['schema'] ?? null,
            'openai' => $corps['text']['format']['schema'] ?? null,
            'gemini' => $corps['generationConfig']['responseJsonSchema'] ?? null,
            default => $corps['response_format']['json_schema']['schema'] ?? null,
        };

        return \is_array($schema) ? $schema : null;
    }

    /** Le modèle demandé au dernier appel (dans l'URL pour Gemini). */
    protected function modeleDemande(): string
    {
        $appel = $this->dernierAppelAuModele();
        if (isset($appel['corps']['model'])) {
            return (string) $appel['corps']['model'];
        }

        return preg_match('#/models/([^/:]+)#', $appel['url'], $m) ? $m[1] : '';
    }

    /**
     * Les résultats d'outils renvoyés au modèle au dernier appel, dans l'ordre : le nom de l'outil et ce qu'il a répondu.
     *
     * @return list<array{outil: string, texte: string}>
     */
    protected function resultatsDOutils(): array
    {
        $appel = $this->dernierAppelAuModele();
        $corps = $appel['corps'];
        $noms = [];
        $resultats = [];
        foreach ($corps['messages'] ?? $corps['input'] ?? $corps['contents'] ?? [] as $message) {
            foreach ($message['tool_calls'] ?? [] as $appelDOutil) {
                $noms[$appelDOutil['id'] ?? ''] = $appelDOutil['function']['name'] ?? '';
            }
            if ('function_call' === ($message['type'] ?? null)) {
                $noms[$message['call_id'] ?? ''] = $message['name'] ?? '';
            }
            if ('function_call_output' === ($message['type'] ?? null)) {
                $resultats[] = ['outil' => $noms[$message['call_id'] ?? ''] ?? '', 'texte' => self::enTexte($message['output'] ?? '')];
            }
            if ('tool' === ($message['role'] ?? null)) {
                $resultats[] = ['outil' => $noms[$message['tool_call_id'] ?? ''] ?? '', 'texte' => self::enTexte($message['content'] ?? '')];
            }
            foreach (\is_array($message['content'] ?? null) ? $message['content'] : $message['parts'] ?? [] as $partie) {
                if (!\is_array($partie)) {
                    continue;
                }
                if ('tool_use' === ($partie['type'] ?? null)) {
                    $noms[$partie['id'] ?? ''] = $partie['name'] ?? '';
                }
                if ('tool_result' === ($partie['type'] ?? null)) {
                    $contenu = $partie['content'] ?? '';
                    $resultats[] = ['outil' => $noms[$partie['tool_use_id'] ?? ''] ?? '', 'texte' => \is_array($contenu) ? self::texteDe($contenu) : self::enTexte($contenu)];
                }
                if (isset($partie['functionResponse'])) {
                    $reponse = $partie['functionResponse']['response'] ?? [];
                    $resultats[] = ['outil' => (string) ($partie['functionResponse']['name'] ?? ''), 'texte' => self::enTexte(\is_array($reponse) && \array_key_exists('result', $reponse) ? $reponse['result'] : $reponse)];
                }
            }
        }

        return $resultats;
    }

    /** Ce que l'outil $nom a répondu en dernier, renvoyé au modèle au dernier appel ; null s'il n'a pas été appelé. */
    protected function resultatDOutil(string $nom): ?string
    {
        $resultats = array_filter($this->resultatsDOutils(), static fn (array $r): bool => $nom === $r['outil']);

        return [] === $resultats ? null : $resultats[array_key_last($resultats)]['texte'];
    }

    /**
     * L'outil $nom tel que le dernier appel le décrit au modèle : sa description et le schéma JSON de ses paramètres ;
     * null s'il n'est pas proposé.
     *
     * @return array{description: string, parametres: array<string, mixed>}|null
     */
    protected function outilPropose(string $nom): ?array
    {
        $corps = $this->dernierAppelAuModele()['corps'];
        foreach ($corps['tools'] ?? [] as $outil) {
            foreach ($outil['functionDeclarations'] ?? [$outil['function'] ?? $outil] as $declaration) {
                if ($nom === ($declaration['name'] ?? null)) {
                    return [
                        'description' => (string) ($declaration['description'] ?? ''),
                        'parametres' => (array) ($declaration['input_schema'] ?? $declaration['parameters'] ?? $declaration['parametersJsonSchema'] ?? []),
                    ];
                }
            }
        }

        return null;
    }

    /** @return list<string> les noms des outils proposés au modèle au dernier appel */
    protected function outilsProposes(): array
    {
        $corps = $this->dernierAppelAuModele()['corps'];
        $noms = [];
        foreach ($corps['tools'] ?? [] as $outil) {
            foreach ($outil['functionDeclarations'] ?? [$outil] as $declaration) {
                $nom = $declaration['name'] ?? $declaration['function']['name'] ?? null;
                if (\is_string($nom)) {
                    $noms[] = $nom;
                }
            }
        }

        return $noms;
    }

    private static function estUnEmbedding(string $url): bool
    {
        return str_contains(strtolower((string) parse_url($url, \PHP_URL_PATH)), 'embed');
    }

    private static function enTexte(mixed $valeur): string
    {
        return \is_string($valeur) ? $valeur : json_encode($valeur, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    private static function texteDe(mixed $contenu): string
    {
        if (\is_string($contenu)) {
            return $contenu;
        }
        if (!\is_array($contenu)) {
            return '';
        }
        $textes = [];
        foreach ($contenu as $partie) {
            if (\is_array($partie) && \is_string($partie['text'] ?? null)) {
                $textes[] = $partie['text'];
            } elseif (\is_string($partie)) {
                $textes[] = $partie;
            }
        }

        return implode("\n", $textes);
    }
}
