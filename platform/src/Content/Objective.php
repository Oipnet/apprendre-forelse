<?php

namespace App\Content;

final readonly class Objective
{
    /** Objectif « vos tests passent sur l'application correcte » (clé « own-tests » d'exercise.yaml). */
    public const string OWN_TESTS = 'own-tests';
    /** Objectif « vos tests détectent ce mutant » : « mutant:<id> ». */
    public const string MUTANT_PREFIX = 'mutant:';

    public function __construct(
        /** Méthode de test cachée qui valide l'objectif, ou OWN_TESTS, ou MUTANT_PREFIX.<id>. */
        public string $test,
        public string $label,
    ) {
    }

    /** Validé par un test caché de l'exercice (et non par les tests de l'apprenant). */
    public function isHiddenTest(): bool
    {
        return self::OWN_TESTS !== $this->test && !str_starts_with($this->test, self::MUTANT_PREFIX);
    }
}
