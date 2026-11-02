<?php

namespace App\Tests\Formation;

/**
 * Une réponse du modèle, écrite une fois et rendue au format de chaque fournisseur : un texte, ou des appels d'outils
 * (chapitre « Outils et agent »), ou des vecteurs (embeddings, chapitre RAG).
 */
final readonly class Reponse
{
    /**
     * @param list<array{nom: string, arguments: array<string, mixed>}> $outils
     * @param list<list<float>>                                       $vecteurs
     */
    private function __construct(
        public string $texte = '',
        public array $outils = [],
        public array $vecteurs = [],
        public int $jetonsEnEntree = 12,
        public int $jetonsEnSortie = 8,
    ) {
    }

    public static function texte(string $texte, int $jetonsEnEntree = 12, int $jetonsEnSortie = 8): self
    {
        return new self(texte: $texte, jetonsEnEntree: $jetonsEnEntree, jetonsEnSortie: $jetonsEnSortie);
    }

    /** Un objet JSON, tel que le rend une sortie structurée. */
    public static function json(array $objet): self
    {
        return new self(texte: json_encode($objet, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $arguments */
    public static function appelDOutil(string $nom, array $arguments = []): self
    {
        return new self(outils: [['nom' => $nom, 'arguments' => $arguments]]);
    }

    /** @param list<float> ...$vecteurs */
    public static function vecteurs(array ...$vecteurs): self
    {
        return new self(vecteurs: array_values($vecteurs));
    }

    /** @return array<string, mixed> le corps JSON que renverrait ce fournisseur */
    public function auFormat(string $fournisseur, string $url): array
    {
        if ([] !== $this->vecteurs) {
            return $this->vecteursAuFormat($fournisseur);
        }

        return match ($fournisseur) {
            'anthropic' => $this->anthropic(),
            'openai' => $this->openai(),
            'mistral' => $this->mistral(),
            'gemini' => $this->gemini(),
        };
    }

    private function anthropic(): array
    {
        $contenu = '' !== $this->texte ? [['type' => 'text', 'text' => $this->texte]] : [];
        foreach ($this->outils as $i => $outil) {
            $contenu[] = ['type' => 'tool_use', 'id' => 'toolu_'.($i + 1), 'name' => $outil['nom'], 'input' => (object) $outil['arguments']];
        }

        return [
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-test',
            'content' => $contenu,
            'stop_reason' => [] === $this->outils ? 'end_turn' : 'tool_use',
            'usage' => ['input_tokens' => $this->jetonsEnEntree, 'output_tokens' => $this->jetonsEnSortie],
        ];
    }

    private function openai(): array
    {
        $sortie = '' !== $this->texte ? [['type' => 'message', 'id' => 'msg_test', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => $this->texte, 'annotations' => []]]]] : [];
        foreach ($this->outils as $i => $outil) {
            $sortie[] = ['type' => 'function_call', 'id' => 'fc_'.($i + 1), 'call_id' => 'call_'.($i + 1), 'name' => $outil['nom'], 'arguments' => json_encode((object) $outil['arguments'], \JSON_THROW_ON_ERROR), 'status' => 'completed'];
        }

        return [
            'id' => 'resp_test', 'object' => 'response', 'status' => 'completed', 'model' => 'gpt-test',
            'output' => $sortie,
            'usage' => ['input_tokens' => $this->jetonsEnEntree, 'output_tokens' => $this->jetonsEnSortie, 'total_tokens' => $this->jetonsEnEntree + $this->jetonsEnSortie],
        ];
    }

    private function mistral(): array
    {
        $message = ['role' => 'assistant', 'content' => $this->texte];
        if ([] !== $this->outils) {
            $message['tool_calls'] = array_map(static fn (array $outil, int $i): array => [
                'id' => 'call_'.($i + 1), 'type' => 'function',
                'function' => ['name' => $outil['nom'], 'arguments' => json_encode((object) $outil['arguments'], \JSON_THROW_ON_ERROR)],
            ], $this->outils, array_keys($this->outils));
        }

        return [
            'id' => 'cmpl_test', 'object' => 'chat.completion', 'model' => 'mistral-test',
            'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => [] === $this->outils ? 'stop' : 'tool_calls']],
            'usage' => ['prompt_tokens' => $this->jetonsEnEntree, 'completion_tokens' => $this->jetonsEnSortie, 'total_tokens' => $this->jetonsEnEntree + $this->jetonsEnSortie],
        ];
    }

    private function gemini(): array
    {
        $parties = '' !== $this->texte ? [['text' => $this->texte]] : [];
        foreach ($this->outils as $outil) {
            $parties[] = ['functionCall' => ['name' => $outil['nom'], 'args' => (object) $outil['arguments']]];
        }

        return [
            'candidates' => [['content' => ['role' => 'model', 'parts' => $parties], 'finishReason' => 'STOP', 'index' => 0]],
            'usageMetadata' => ['promptTokenCount' => $this->jetonsEnEntree, 'candidatesTokenCount' => $this->jetonsEnSortie, 'totalTokenCount' => $this->jetonsEnEntree + $this->jetonsEnSortie],
            'modelVersion' => 'gemini-test',
        ];
    }

    private function vecteursAuFormat(string $fournisseur): array
    {
        return match ($fournisseur) {
            'gemini' => ['embeddings' => array_map(static fn (array $v): array => ['values' => $v], $this->vecteurs)],
            default => [
                'object' => 'list',
                'data' => array_map(static fn (array $v, int $i): array => ['object' => 'embedding', 'index' => $i, 'embedding' => $v], $this->vecteurs, array_keys($this->vecteurs)),
                'model' => 'embedding-test',
                'usage' => ['prompt_tokens' => 4, 'total_tokens' => 4],
            ],
        };
    }
}
