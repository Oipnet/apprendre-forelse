<?php

namespace App\Content;

final readonly class Exercise
{
    /** La langue d'un exercice écrit dans son dossier ; ses traductions vivent dans `i18n/<langue>/` (voir ExerciseReader::translate). */
    public const string LOCALE = 'fr';
    /** Les langues dans lesquelles un exercice (de Pratique ou de parcours) et un parcours peuvent être traduits. */
    public const array TRANSLATIONS = ['en'];

    /**
     * @param list<string>    $concepts
     * @param list<string>    $editable   fichiers modifiables par l'apprenant ; un motif (`migrations/*.php`)
     *                                    couvre aussi les fichiers qu'une commande console va créer
     * @param list<string>    $readonly   fichiers visibles mais verrouillés
     * @param list<Objective> $objectives
     * @param list<string>    $hints      indices gradués (markdown)
     * @param list<string>    $setup      commandes console lancées au chargement de l'aperçu
     * @param list<ExampleRequest> $requests requêtes d'exemple de l'onglet « Requêtes »
     * @param list<DocLink>        $docs     documentation utile pour résoudre l'exercice
     * @param list<Mutant>         $mutants  versions cassées de l'application, que les tests de l'apprenant doivent détecter
     * @param list<string>         $formerIds
     * @param list<ExerciseStep>   $steps    exercice à étapes : $editable les réunit toutes, $objectives sont ceux de la dernière
     */
    public function __construct(
        public string $id,
        /** Le parcours de l'exercice, ou null pour un exercice de Pratique (voir Practice). */
        public ?string $trackId,
        public string $title,
        public array $concepts,
        public int $xp,
        public string $environment,
        public ?string $base,
        public string $open,
        public string $preview,
        public array $editable,
        public array $readonly,
        public array $objectives,
        public array $hints,
        public string $instructions,
        public string $directory,
        public array $setup = [],
        public array $requests = [],
        public array $docs = [],
        public array $mutants = [],
        /** Durée estimée, en minutes, pour le public du parcours (clé `duration`, facultative). */
        public ?int $duration = null,
        /** Anciens identifiants (clé « former_ids ») : leurs adresses redirigent vers celle de l'exercice. */
        public array $formerIds = [],
        public string $locale = self::LOCALE,
        /**
         * Une traduction : son dossier (`i18n/<langue>/`), dont les fichiers starter/, solution/ et tests/ remplacent,
         * chemin par chemin, ceux de l'exercice français.
         */
        public ?string $overlay = null,
        public array $steps = [],
    ) {
    }

    public function hasSteps(): bool
    {
        return [] !== $this->steps;
    }

    /**
     * Les objectifs de toutes les étapes (exercice à étapes), ou ceux de l'exercice.
     *
     * @return list<Objective>
     */
    public function allObjectives(): array
    {
        return $this->hasSteps() ? array_merge(...array_map(static fn (ExerciseStep $step) => $step->objectives, $this->steps)) : $this->objectives;
    }

    /**
     * Les mutants à essayer pour ces objectifs : pour un exercice à étapes, ceux qu'ils citent ; sinon tous.
     *
     * @param list<Objective>|null $objectives null : ceux de l'exercice
     *
     * @return list<Mutant>
     */
    public function mutantsFor(?array $objectives = null): array
    {
        if (!$this->hasSteps() && null === $objectives) {
            return $this->mutants;
        }
        $ids = array_filter(array_map(static fn (Objective $o) => $o->mutantId(), $objectives ?? $this->objectives));

        return array_values(array_filter($this->mutants, static fn (Mutant $m) => \in_array($m->id, $ids, true)));
    }

    /** Le dernier exercice d'un chapitre, reconnaissable à son titre : le pack ne le déclare pas autrement. */
    public function isBoss(): bool
    {
        return 1 === preg_match('/\bboss\b/iu', $this->title);
    }

    /**
     * Les tests écrits par l'apprenant : ses fichiers éditables sous tests/.
     *
     * @return list<string>
     */
    public function ownTests(): array
    {
        // Classe PHPUnit (…Test.php) ou fichier Vitest (….test.ts, ….spec.ts) d'un projet Nuxt.
        return array_values(array_filter($this->editablePaths(), static fn (string $path) => str_starts_with($path, 'tests/') && (str_ends_with($path, 'Test.php') || 1 === preg_match('/\.(test|spec)\.[cm]?[jt]sx?$/', $path))));
    }

    /**
     * Les fichiers modifiables nommés explicitement (sans motif).
     *
     * @return list<string>
     */
    public function editablePaths(): array
    {
        return array_values(array_filter($this->editable, static fn (string $entry) => !str_contains($entry, '*')));
    }

    /**
     * L'apprenant peut-il modifier ce fichier ? `*` ne franchit pas un « / » : `migrations/*.php`
     * couvre `migrations/Version1.php`, pas `migrations/archives/Version1.php`.
     */
    public function isEditable(string $path): bool
    {
        // Un joker de fnmatch accepte « .. » comme segment : un chemin soumis n'a jamais de segment « . » ni « .. ».
        if (array_intersect(explode('/', $path), ['.', '..'])) {
            return false;
        }
        foreach ($this->editable as $entry) {
            if ($entry === $path || (str_contains($entry, '*') && fnmatch($entry, $path, \FNM_PATHNAME))) {
                return true;
            }
        }

        return false;
    }
}
