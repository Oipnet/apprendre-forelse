<?php

namespace App\Content\Check;

/**
 * La note d'un état du projet : réussite par test (méthodes des tests cachés et de l'apprenant), plus les
 * objectifs « own-tests » et « mutant:<id> » quand l'exercice note les tests de l'apprenant.
 */
final readonly class Grade
{
    /**
     * @param array<string, bool>|null $results  null si les tests n'ont rien rapporté
     * @param array<string, string>    $failures ce qui cloche, par test ou objectif (RunReport::GLOBAL sans rapport)
     */
    public function __construct(
        public ?array $results,
        public array $failures,
    ) {
    }

    public function failure(string $test, string $default = '?'): string
    {
        return $this->failures[$test] ?? $default;
    }
}
