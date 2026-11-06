<?php

namespace App\Content;

final readonly class Objective
{
    /** Objectif « vos tests passent sur l'application correcte » (clé « own-tests » d'exercise.yaml). */
    public const string OWN_TESTS = 'own-tests';
    /** Objectif « vos tests détectent ce mutant » : « mutant:<id> ». */
    public const string MUTANT_PREFIX = 'mutant:';
    /** Étape rouge (voir ExerciseStep) : les tests de l'apprenant échouent sur son code (« own-tests: fail »). */
    public const string OWN_TESTS_FAIL = 'own-tests-fail';
    /** Étape rouge : … et passent sur le code attendu, l'état de l'étape suivante (« own-tests: reference »). */
    public const string OWN_TESTS_REFERENCE = 'own-tests-reference';

    public function __construct(
        /** Méthode de test cachée qui valide l'objectif, ou OWN_TESTS (OWN_TESTS_FAIL, OWN_TESTS_REFERENCE), ou MUTANT_PREFIX.<id>. */
        public string $test,
        public string $label,
    ) {
    }

    /** Validé par un test caché de l'exercice (et non par les tests de l'apprenant). */
    public function isHiddenTest(): bool
    {
        return !\in_array($this->test, [self::OWN_TESTS, self::OWN_TESTS_FAIL, self::OWN_TESTS_REFERENCE], true) && !str_starts_with($this->test, self::MUTANT_PREFIX);
    }

    /** L'id du mutant d'un objectif « mutant:<id> », null pour un autre objectif. */
    public function mutantId(): ?string
    {
        return str_starts_with($this->test, self::MUTANT_PREFIX) ? substr($this->test, \strlen(self::MUTANT_PREFIX)) : null;
    }
}
