<?php

namespace App\Ai;

use App\Content\ContentRepository;
use App\Content\Exercise;

/**
 * Le mentor de l'apprenant : une revue de code quand les tests passent, une explication quand
 * une erreur survient. Il intervient là où un bon mentor interviendrait, et jamais à la place
 * de l'apprenant : pas de solution, pas de correction toute faite.
 *
 * Facultatif, comme l'assistance aux auteurs : sans clé d'API, les boutons n'apparaissent pas.
 */
final class Mentor
{
    /** Au-delà, un fichier est tronqué : le modèle n'a pas besoin de tout, et la facture non plus. */
    private const int MAX_FILE_CHARS = 12000;
    private const int MAX_ERROR_CHARS = 6000;
    private const int MAX_POINTS = 4;

    public function __construct(
        private readonly ModelClient $modele,
        private readonly ContentRepository $content,
    ) {
    }

    public function disponible(): bool
    {
        return $this->modele->disponible();
    }

    /**
     * Revue du code qui vient de réussir l'exercice : ce qui pourrait être plus idiomatique,
     * plus lisible ou plus sûr. La solution de référence sert de point de comparaison au modèle ;
     * elle n'est jamais renvoyée.
     *
     * @param array<string, string> $fichiers fichiers de l'apprenant, par chemin
     *
     * @return array{summary: string, points: list<array{file: string, line: int|null, message: string, snippet: string|null}>}
     */
    public function revue(Exercise $exercise, array $fichiers): array
    {
        $consignes = <<<'TXT'
            Vous êtes le mentor d'une plateforme où l'on apprend à développer en codant dans le navigateur.
            L'apprenant vient de réussir un exercice : tous les tests passent. Faites-lui une revue de code
            courte, comme un développeur senior bienveillant en revue de pull request.

            Règles :
            - Écrivez en français, en vouvoyant l'apprenant. Ton direct, concret, jamais condescendant.
            - Concentrez-vous sur ce qui pourrait être plus idiomatique (conventions du framework), plus lisible,
              plus robuste ou plus sûr. Ignorez le style pur (espaces, ordre des imports).
            - Au plus quatre points, du plus important au moins important. Chaque point désigne un fichier,
              si possible une ligne, et explique le pourquoi en deux ou trois phrases.
            - Un extrait d'une à trois lignes peut illustrer un point. Ne réécrivez jamais un fichier entier.
            - Si le code est bon, dites-le franchement et n'inventez pas de reproche : zéro point est une
              réponse acceptable.
            - La solution de référence vous est donnée pour comparer : ne la citez pas, ne dites pas
              « la solution attendue ». L'apprenant a le droit d'avoir fait autrement.
            TXT;

        $contexte = $this->contexte($exercise, $fichiers);
        $contexte .= "\n\n# Solution de référence (pour comparaison, à ne pas citer)\n".$this->listerFichiers($this->content->solutionFiles($exercise));

        $reponse = $this->modele->appeler($consignes, [['role' => 'user', 'content' => $contexte]], [
            'name' => 'revue_de_code',
            'description' => 'La revue de code : un résumé et des points précis.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string', 'description' => 'Deux ou trois phrases : l\'impression générale, ce qui est bien fait.'],
                    'points' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'file' => ['type' => 'string', 'description' => 'Chemin du fichier concerné, tel que fourni.'],
                                'line' => ['type' => ['integer', 'null'], 'description' => 'Ligne concernée, si elle est identifiable.'],
                                'message' => ['type' => 'string', 'description' => 'Le point, et pourquoi il compte. Markdown léger (code inline, gras).'],
                                'snippet' => ['type' => ['string', 'null'], 'description' => 'Un extrait d\'une à trois lignes pour illustrer, ou null.'],
                            ],
                            'required' => ['file', 'line', 'message', 'snippet'],
                        ],
                    ],
                ],
                'required' => ['summary', 'points'],
            ],
        ], maxTokens: 2000);

        $points = [];
        foreach (\is_array($reponse['points'] ?? null) ? $reponse['points'] : [] as $point) {
            if (!\is_array($point) || !\is_string($point['message'] ?? null) || '' === trim($point['message'])) {
                continue;
            }
            $points[] = [
                'file' => \is_string($point['file'] ?? null) ? $point['file'] : '',
                'line' => \is_int($point['line'] ?? null) && $point['line'] > 0 ? $point['line'] : null,
                'message' => trim($point['message']),
                'snippet' => \is_string($point['snippet'] ?? null) && '' !== trim($point['snippet']) ? rtrim($point['snippet']) : null,
            ];
        }

        return [
            'summary' => \is_string($reponse['summary'] ?? null) ? trim($reponse['summary']) : '',
            'points' => \array_slice($points, 0, self::MAX_POINTS),
        ];
    }

    /**
     * Une erreur (page d'exception de l'aperçu, tests qui plantent, commande en échec) traduite en
     * cause probable et en piste, sans la correction : c'est à l'apprenant de la trouver.
     *
     * @param array<string, string> $fichiers
     * @param 'preview'|'tests'|'console' $source
     *
     * @return array{cause: string, piste: string, file: string|null}
     */
    public function expliquer(Exercise $exercise, array $fichiers, string $erreur, string $source): array
    {
        $consignes = <<<'TXT'
            Vous êtes le mentor d'une plateforme où l'on apprend à développer en codant dans le navigateur.
            L'apprenant vient de rencontrer une erreur. Expliquez-la comme le ferait un bon mentor à côté
            de lui : la cause probable, en termes qu'il comprend, puis une piste pour la trouver lui-même.

            Règles :
            - Écrivez en français, en vouvoyant l'apprenant. Court : la cause en deux ou trois phrases,
              la piste en une ou deux.
            - Traduisez le jargon : dites ce que signifie l'erreur dans le contexte de son code.
            - La piste dit où regarder et quoi vérifier (un fichier, une méthode, une convention, une page
              de documentation). Elle ne donne PAS la correction : pas de code corrigé, pas la ligne à écrire,
              pas le nom exact qu'il aurait fallu utiliser. Il doit faire le dernier pas seul.
            - Si l'erreur ne vient visiblement pas de son code (environnement, exercice), dites-le.
            TXT;

        $sources = ['preview' => 'l\'aperçu de l\'application (réponse HTTP en erreur)', 'tests' => 'l\'exécution des tests', 'console' => 'une commande de la console du projet (bin/console, php artisan ou docker, selon le projet)'];
        $contexte = $this->contexte($exercise, $fichiers);
        $contexte .= "\n\n# L'erreur\nSource : ".($sources[$source] ?? $source)."\n\n```\n".$this->tronquer($erreur, self::MAX_ERROR_CHARS)."\n```";

        $reponse = $this->modele->appeler($consignes, [['role' => 'user', 'content' => $contexte]], [
            'name' => 'expliquer_erreur',
            'description' => 'L\'explication : la cause probable et une piste, sans la correction.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'cause' => ['type' => 'string', 'description' => 'Ce que l\'erreur signifie ici, et d\'où elle vient probablement. Markdown léger.'],
                    'piste' => ['type' => 'string', 'description' => 'Où regarder et quoi vérifier, sans donner la correction. Markdown léger.'],
                    'file' => ['type' => ['string', 'null'], 'description' => 'Le fichier de l\'apprenant probablement en cause, parmi ceux fournis, ou null.'],
                ],
                'required' => ['cause', 'piste', 'file'],
            ],
        ], maxTokens: 1000);

        $file = \is_string($reponse['file'] ?? null) && isset($fichiers[$reponse['file']]) ? $reponse['file'] : null;

        return [
            'cause' => \is_string($reponse['cause'] ?? null) ? trim($reponse['cause']) : '',
            'piste' => \is_string($reponse['piste'] ?? null) ? trim($reponse['piste']) : '',
            'file' => $file,
        ];
    }

    /**
     * Ce que le modèle doit savoir de l'exercice et du code de l'apprenant. Seuls ses fichiers
     * éditables sont transmis : le reste est l'environnement, connu du modèle.
     *
     * @param array<string, string> $fichiers
     */
    private function contexte(Exercise $exercise, array $fichiers): string
    {
        $objectifs = implode("\n", array_map(static fn ($o) => '- '.$o->label, $exercise->objectives));
        $editables = array_filter($fichiers, static fn (string $chemin) => $exercise->isEditable($chemin), \ARRAY_FILTER_USE_KEY);

        return "# L'exercice : {$exercise->title}\nNotions : ".implode(', ', $exercise->concepts)."\n\n## Consignes\n".$exercise->instructions
            ."\n\n## Objectifs (chacun vérifié par un test)\n".$objectifs
            ."\n\n# Le code de l'apprenant\n".$this->listerFichiers($editables);
    }

    /** @param array<string, string> $fichiers */
    private function listerFichiers(array $fichiers): string
    {
        if (!$fichiers) {
            return '(aucun fichier)';
        }
        $blocs = [];
        foreach ($fichiers as $chemin => $contenu) {
            $blocs[] = "## {$chemin}\n```\n".$this->tronquer($contenu, self::MAX_FILE_CHARS)."\n```";
        }

        return implode("\n\n", $blocs);
    }

    private function tronquer(string $texte, int $max): string
    {
        return mb_strlen($texte) > $max ? mb_substr($texte, 0, $max)."\n… (tronqué)" : $texte;
    }
}
