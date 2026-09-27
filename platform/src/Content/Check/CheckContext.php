<?php

namespace App\Content\Check;

use App\Content\Environment;

/** Ce que les vérifications d'un exercice partagent : son environnement, son lanceur de tests et ses fichiers. */
final readonly class CheckContext
{
    /**
     * @param array<string, string> $starting état de départ, par chemin
     * @param array<string, string> $tests    tests cachés
     * @param array<string, string> $solution solution de référence
     */
    public function __construct(
        public Environment $environment,
        public TestRunner $runner,
        public array $starting,
        public array $tests,
        public array $solution,
    ) {
    }
}
