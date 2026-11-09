<?php

namespace App\Content\Check;

use App\Content\Environment;
use App\Content\Exercise;
use App\Content\ExerciseStep;
use App\Content\Objective;
use App\Content\StepPhase;

/**
 * Note les tests que l'apprenant écrit (fichiers éditables sous tests/), comme le navigateur (voir
 * playground/src/runtime/php-worker.ts, même logique) :
 *  - « own-tests » : ils passent tous sur l'application correcte (au moins un test) ;
 *  - « mutant:<id> » : ils échouent sur l'application cassée par ce mutant ;
 *  - dans une étape rouge, « own-tests-fail » : au moins un échoue sur l'état courant, et « own-tests-reference » :
 *    ils passent tous sur le code attendu de l'étape (ses fichiers posés le temps d'un run, puis retirés).
 */
final readonly class MutantGrader
{
    public function __construct(
        private ProjectWorkdir $workdirs,
    ) {
    }

    /**
     * @param RunReport             $report    le lancement de tous les tests sur l'état courant du projet
     * @param ExerciseStep|null     $step      l'étape notée (exercice à étapes), null : les objectifs de l'exercice
     * @param array<string, string> $reference étape rouge : le code attendu (voir ExerciseSources::stepReferenceFiles)
     */
    public function grade(Exercise $exercise, RunReport $report, TestRunner $runner, Environment $environment, string $workdir, ?ExerciseStep $step = null, array $reference = []): Grade
    {
        if (!$report->ran()) {
            return new Grade(null, $report->failures);
        }
        $results = $report->passed();
        $failures = $report->failures;
        $ownTests = $exercise->ownTests();
        if (!$ownTests) {
            return new Grade($results, $failures);
        }

        $own = $report->casesIn($ownTests);
        $ownPassing = $own && !array_filter($own, static fn (array $case) => 'passed' !== $case['status']);
        $results[Objective::OWN_TESTS] = $ownPassing;
        if (!$ownPassing) {
            $failures[Objective::OWN_TESTS] = $own ? 'des tests de l\'apprenant échouent sur l\'application correcte.' : 'aucun test de l\'apprenant exécuté.';
        }

        if (StepPhase::Red === $step?->phase) {
            $results[Objective::OWN_TESTS_FAIL] = [] !== array_filter($own, static fn (array $case) => 'failed' === $case['status']);
            if (!$results[Objective::OWN_TESTS_FAIL]) {
                $failures[Objective::OWN_TESTS_FAIL] = $own ? 'les tests de l\'apprenant passent tous : aucun n\'est rouge.' : 'aucun test de l\'apprenant exécuté.';
            }
            $results[Objective::OWN_TESTS_REFERENCE] = $this->passesOn($reference, $ownTests, $runner, $environment, $workdir);
            if (!$results[Objective::OWN_TESTS_REFERENCE]) {
                $failures[Objective::OWN_TESTS_REFERENCE] = 'les tests de l\'apprenant échouent aussi sur le code attendu.';
            }
        }

        foreach ($exercise->mutantsFor($step?->objectives) as $mutant) {
            $key = Objective::MUTANT_PREFIX.$mutant->id;
            if (!$ownPassing) {
                $results[$key] = false;
                continue;
            }
            $originals = [];
            $mutated = [];
            foreach ($mutant->changes as $change) {
                $path = $change['file'];
                $originals[$path] ??= (string) file_get_contents($workdir.'/'.$path);
                $mutated[$path] = str_replace($change['search'], $change['replace'], $mutated[$path] ?? $originals[$path]);
            }
            $this->workdirs->write($workdir, $mutated);
            $this->workdirs->clearCaches($workdir, $environment->cacheDirs);
            $mutantReport = $runner->run($environment, $workdir, $ownTests);
            $this->workdirs->write($workdir, $originals);
            $this->workdirs->clearCaches($workdir, $environment->cacheDirs);

            // Détecté si un test de l'apprenant échoue (ou si les tests s'arrêtent : l'application est cassée).
            $results[$key] = $mutantReport->hasFailure();
            if (!$results[$key]) {
                $failures[$key] = sprintf('les tests passent encore quand %s.', $mutant->label);
            }
        }

        return new Grade($results, $failures);
    }

    /**
     * Les tests de l'apprenant passent-ils tous sur ce code ? Ses fichiers sont posés le temps du run, puis le projet
     * est remis comme avant (les fichiers ajoutés retirés).
     *
     * @param array<string, string> $files
     * @param list<string>          $ownTests
     */
    private function passesOn(array $files, array $ownTests, TestRunner $runner, Environment $environment, string $workdir): bool
    {
        $originals = [];
        $added = [];
        foreach (array_keys($files) as $path) {
            if (is_file($workdir.'/'.$path)) {
                $originals[$path] = (string) file_get_contents($workdir.'/'.$path);
            } else {
                $added[] = $path;
            }
        }
        $this->workdirs->write($workdir, $files);
        $this->workdirs->clearCaches($workdir, $environment->cacheDirs);
        try {
            $report = $runner->run($environment, $workdir, $ownTests);
        } finally {
            $this->workdirs->write($workdir, $originals);
            $this->workdirs->delete($workdir, $added);
            $this->workdirs->clearCaches($workdir, $environment->cacheDirs);
        }
        $own = $report->casesIn($ownTests);

        return $report->ran() && $own && !array_filter($own, static fn (array $case) => 'passed' !== $case['status']);
    }
}
