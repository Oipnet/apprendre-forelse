<?php

namespace App\Content;

/**
 * Une étape d'un exercice à étapes (clé « steps » d'exercise.yaml) : l'apprenant garde son code d'une étape à
 * l'autre ; chaque étape a sa consigne (steps/<id>/instructions.md), ses fichiers modifiables, ses objectifs et sa
 * solution (steps/<id>/solution/, ajoutée à celles des étapes précédentes). Les fichiers modifiables d'une autre
 * étape restent visibles, en lecture seule.
 *
 * Une étape rouge se valide quand les tests de l'apprenant échouent sur son code (Objective::OWN_TESTS_FAIL) et
 * passent sur le code attendu, l'état de l'étape suivante (Objective::OWN_TESTS_REFERENCE).
 */
final readonly class ExerciseStep
{
    /**
     * @param list<string>    $editable   fichiers modifiables pendant l'étape (motifs acceptés, comme Exercise::$editable)
     * @param list<Objective> $objectives
     */
    public function __construct(
        public string $id,
        public StepPhase $phase,
        public string $title,
        /** Markdown. */
        public string $instructions,
        public array $editable,
        public string $open,
        public array $objectives,
    ) {
    }

    public function isEditable(string $path): bool
    {
        foreach ($this->editable as $entry) {
            if ($entry === $path || (str_contains($entry, '*') && fnmatch($entry, $path, \FNM_PATHNAME))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les mutants que les objectifs de l'étape citent.
     *
     * @return list<string>
     */
    public function mutantIds(): array
    {
        return array_values(array_filter(array_map(static fn (Objective $o) => $o->mutantId(), $this->objectives)));
    }
}
