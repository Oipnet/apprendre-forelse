<?php

namespace App\Content\Check;

use App\Content\Environment;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Lance les tests d'un projet reconstitué, comme le navigateur les lance : un lanceur par valeur de
 * FrameworkProfile::$testRunner (voir TestRunners). Ajouter un lanceur ne demande rien à ExerciseChecker.
 */
#[AutoconfigureTag]
interface TestRunner
{
    /** Pourquoi les tests de cet environnement ne peuvent pas tourner ici (message pour l'auteur), ou null. */
    public function unavailable(Environment $environment): ?string;

    /** Le code des tests déclare-t-il le test de ce nom ? */
    public function declares(string $testCode, string $test): bool;

    /** Comment se nomme un test pour ce lanceur, dans les messages (« méthode de test »). */
    public function testNoun(): string;

    /** @param list<string> $paths fichiers de test à lancer (tous par défaut) */
    public function run(Environment $environment, string $workdir, array $paths = []): RunReport;
}
