<?php

namespace App\Content\Check;

use App\Content\Environment;
use App\Content\Exercise;
use App\Content\Objective;

/**
 * Note les tests que l'apprenant écrit (fichiers éditables sous tests/), comme le navigateur (voir
 * playground/src/runtime/php-worker.ts, même logique) :
 *  - « own-tests » : ils passent tous sur l'application correcte (au moins un test) ;
 *  - « mutant:<id> » : ils échouent sur l'application cassée par ce mutant.
 */
final readonly class MutantGrader
{
    public function __construct(
        private ProjectWorkdir $workdirs,
    ) {
    }

    /** @param RunReport $report le lancement de tous les tests sur l'état courant du projet */
    public function grade(Exercise $exercise, RunReport $report, TestRunner $runner, Environment $environment, string $workdir): Grade
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

        foreach ($exercise->mutants as $mutant) {
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
}
