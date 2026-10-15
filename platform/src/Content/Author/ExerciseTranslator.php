<?php

namespace App\Content\Author;

use App\Ai\ModelClient;
use App\Ai\PromptFiles;
use App\Content\ContentException;
use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Content\ExerciseReader;

/**
 * La traduction d'un exercice de Pratique proposée par un modèle : le dossier `i18n/<langue>/` entier (voir
 * ExerciseReader::translate()), à relire dans l'atelier avant de l'enregistrer.
 *
 * Facultatif comme le reste de l'assistance : sans clé d'API, l'auteur traduit à la main.
 */
final class ExerciseTranslator
{
    /** La seule langue de traduction pour l'instant (Exercise::TRANSLATIONS). */
    public const string LANGUE = 'en';

    /** L'outil impose la forme de la réponse : des fichiers, rien d'autre. */
    private const array OUTIL = [
        'name' => 'ecrire_traduction',
        'description' => 'Écrit les fichiers de la traduction anglaise de l\'exercice.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'fichiers' => [
                    'type' => 'object',
                    'description' => 'Contenu complet de chaque fichier de la traduction, par chemin relatif au dossier de traduction (exercise.yaml, instructions.md, et les fichiers de starter/, solution/, tests/ qui changent).',
                    'additionalProperties' => ['type' => 'string'],
                ],
            ],
            'required' => ['fichiers'],
        ],
    ];

    public function __construct(
        private readonly ModelClient $modele,
        private readonly ContentRepository $content,
        private readonly ExerciseFiles $fichiers,
    ) {
    }

    public function disponible(): bool
    {
        return $this->modele->disponible();
    }

    /**
     * Propose la traduction d'un exercice de Pratique. Une traduction existante sert de point de départ : le modèle
     * la met à jour au lieu de tout réécrire.
     *
     * @param array<string, string> $existante la traduction actuelle, par chemin relatif à i18n/<langue>/
     *
     * @return array<string, string> par chemin relatif à i18n/<langue>/, « source » à jour
     */
    public function traduire(Exercise $exercise, array $existante = []): array
    {
        $francais = $this->fichiers->read($exercise->directory);
        $entree = $this->modele->appeler(self::CONSIGNES, [[
            'role' => 'user',
            'content' => implode("\n\n", array_filter([
                $this->exemple($exercise),
                "L'exercice français à traduire :\n\n".PromptFiles::render($francais),
                $existante ? "Sa traduction actuelle, écrite pour une version antérieure de l'exercice : garde ce qui reste juste, mets à jour le reste.\n\n".PromptFiles::render($existante) : null,
                'Écris maintenant la traduction anglaise complète.',
            ])),
        ]], self::OUTIL);
        if (!\is_array($entree['fichiers'] ?? null)) {
            throw new ContentException('Le modèle n\'a pas renvoyé de fichiers.');
        }

        return $this->valider($entree['fichiers'], $francais, ExerciseReader::fingerprint($exercise->directory));
    }

    /**
     * @param array<mixed>          $fichiers
     * @param array<string, string> $francais
     *
     * @return array<string, string>
     */
    private function valider(array $fichiers, array $francais, string $empreinte): array
    {
        $valides = [];
        foreach ($fichiers as $chemin => $contenu) {
            if (!\is_string($chemin) || !\is_string($contenu)) {
                throw new ContentException('Le modèle a renvoyé un fichier illisible.');
            }
            $this->fichiers->assertChemin($chemin);
            // Un fichier traduit remplace un fichier français : un chemin inventé serait refusé au chargement.
            if (!\in_array($chemin, ExerciseFiles::ROOT_FILES, true) && !isset($francais[$chemin])) {
                continue;
            }
            // Recopié tel quel, il n'apporte rien : le fichier français suffit.
            if (isset($francais[$chemin]) && !\in_array($chemin, ExerciseFiles::ROOT_FILES, true) && $francais[$chemin] === $contenu) {
                continue;
            }
            $valides[$chemin] = $contenu;
        }
        foreach (ExerciseFiles::ROOT_FILES as $indispensable) {
            if (!isset($valides[$indispensable])) {
                throw new ContentException(sprintf('Le modèle a oublié %s.', $indispensable));
            }
        }
        // L'empreinte se calcule, elle ne se devine pas : celle de l'exercice français tel qu'il est sur le disque.
        $yaml = rtrim((string) preg_replace('/^source:.*\R?/m', '', $valides['exercise.yaml']));
        $valides['exercise.yaml'] = $yaml."\nsource: '".$empreinte."'\n";
        ksort($valides);

        return $valides;
    }

    /** Une traduction existante du même framework, à prendre pour modèle : l'exercice français, puis sa traduction. */
    private function exemple(Exercise $exercise): ?string
    {
        foreach ($this->content->practices(self::LANGUE) as $practice) {
            $traduit = $practice->exercise;
            if ($traduit->id === $exercise->id || $traduit->environment !== $exercise->environment) {
                continue;
            }

            return sprintf(
                "Un exemple d'exercice déjà traduit, pour le style et le format.\n\nL'exercice français :\n\n%s\n\nSa traduction (i18n/%s/) :\n\n%s",
                PromptFiles::render($this->fichiers->read($traduit->directory), 4000),
                self::LANGUE,
                PromptFiles::render($this->fichiers->read($traduit->directory.'/i18n/'.self::LANGUE), 4000),
            );
        }

        return null;
    }

    private const string CONSIGNES = <<<'TXT'
        Tu traduis en anglais un exercice de Pratique d'une plateforme où le développeur code dans son navigateur.
        Tu réponds uniquement en appelant l'outil ecrire_traduction. La traduction vit dans le dossier i18n/en/ de
        l'exercice et se superpose à l'exercice français.

        Les fichiers de la traduction :
        - exercise.yaml : UNIQUEMENT les clés traduisibles présentes dans l'exercice français, parmi title, summary,
          concepts, objectives, hints, requests, docs, preview, readonly, mutants (readonly seulement pour viser l'équivalent
          d'un fichier de langue, lang/en/… au lieu de lang/fr/…). Aucune autre clé (ni id, ni environment, ni
          published, ni visibility, ni source : l'empreinte est ajoutée par la plateforme).
        - instructions.md : les consignes complètes, en anglais.
        - starter/…, solution/…, tests/… : seulement les fichiers dont le texte change. Chaque fichier traduit
          remplace le fichier français au MÊME chemin : n'invente aucun chemin, ne renomme aucun fichier.

        Règles impératives :
        - Un anglais naturel et précis, celui d'une documentation technique : pas de mot à mot.
        - Le code se comporte exactement comme le code français : les tests doivent toujours échouer sur le code
          de départ et passer avec la solution.
        - Les noms de classes et les chemins de fichiers ne changent pas (l'autoload les lie). Traduis les
          commentaires, les chaînes affichées, les messages d'échec des tests, les TODO, et les noms de méthodes de
          test, de variables ou de routes quand c'est utile à la lecture, en reportant chaque changement partout où
          il compte (instructions.md, objectives[].test, requests, preview, hints).
        - objectives : même nombre, même ordre ; « test » nomme la méthode de test telle qu'elle s'appelle dans les
          tests traduits.
        - mutants : les mêmes ids que l'exercice français ; les « label » traduits ; chaque « search » reprend mot
          pour mot une ligne du code traduit.
        - docs : garde les URL, en préférant la version anglaise d'une page (php.net/manual/en/…) ; traduis les titres.
        - concepts : les mêmes notions, en anglais.
        - Ne nomme jamais une méthode d'aide de test run, status ou runCommand : ce sont des méthodes de PHPUnit
          ou de KernelTestCase.
        TXT;
}
