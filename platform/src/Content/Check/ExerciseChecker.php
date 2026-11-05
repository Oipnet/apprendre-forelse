<?php

namespace App\Content\Check;

use App\Content\ContentRepository;
use App\Content\EnvironmentAssembler;
use App\Content\EnvironmentRegistry;
use App\Content\Exercise;
use App\Content\ExerciseStep;
use App\Content\Objective;
use App\Content\StepPhase;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Vérifie qu'un exercice est jouable :
 *  1. le contenu, par chaque ExerciseCheck (cohérence, version de Pratique, index de complétion) ;
 *  2. état de départ + tests → les tests ne doivent PAS être entièrement verts ;
 *  3. + solution → les tests doivent être entièrement verts, et chaque objectif doit avoir été exécuté.
 *
 * Un exercice à étapes se vérifie étape par étape (voir checkSteps) : chacune part de la solution de la précédente.
 *
 * Les tests sont lancés par le TestRunner du framework (PHPUnit natif, ou le module Node qu'il déclare), et
 * les tests de l'apprenant notés par MutantGrader. Cette classe ne fait qu'orchestrer.
 */
final readonly class ExerciseChecker
{
    /**
     * @param iterable<ExerciseCheck> $checks
     */
    public function __construct(
        private ContentRepository $content,
        private EnvironmentRegistry $environments,
        private TestRunners $runners,
        private MutantGrader $grader,
        #[AutowireIterator(ExerciseCheck::class)]
        private iterable $checks,
        private ProjectWorkdir $workdirs = new ProjectWorkdir(),
        /** Reconstitue le projet : la chaîne d'environnements superposée (voir EnvironmentAssembler). */
        private EnvironmentAssembler $assembler = new EnvironmentAssembler(),
    ) {
    }

    /**
     * @param bool $keepWorkdir conserve le projet reconstitué (chemin dans CheckResult::$workdir)
     */
    public function check(Exercise $exercise, bool $keepWorkdir = false): CheckResult
    {
        $result = new CheckResult($exercise);
        $environment = $this->environments->get($exercise->environment);
        $runner = $this->runners->for($environment->framework);
        $context = new CheckContext(
            $environment,
            $runner,
            $this->content->startingFiles($exercise),
            $this->content->testFiles($exercise),
            $this->content->solutionFiles($exercise),
            array_map(fn (int $step) => $this->content->stepSolutionFiles($exercise, $step), array_keys($exercise->steps)),
        );

        foreach ($this->checks as $check) {
            $check->check($exercise, $context, $result);
        }
        if (!$result->isOk()) {
            return $result;
        }
        if (null !== ($unavailable = $runner->unavailable($environment))) {
            $result->error($unavailable);

            return $result;
        }

        $runnerLabel = $environment->framework->testRunnerLabel();
        $workdir = $this->workdirs->create();
        try {
            $this->assembler->assemble($environment, $workdir);
            $this->workdirs->write($workdir, [...$context->starting, ...$context->tests]);
            if ($exercise->hasSteps()) {
                $this->checkSteps($exercise, $context, $workdir, $result);

                return $result;
            }

            $before = $this->grade($exercise, $context, $workdir);
            if (null === $before->results) {
                $result->error('État de départ : '.$runnerLabel.' n\'a produit aucun rapport. '.$before->failure(RunReport::GLOBAL, '(erreur fatale ?)'));
            } elseif (!\in_array(false, $before->results, true)) {
                $result->error('État de départ : tous les tests passent déjà, l\'exercice n\'a rien à faire.');
            }

            $this->workdirs->write($workdir, $context->solution);
            $this->workdirs->clearCaches($workdir, $environment->cacheDirs);
            $after = $this->grade($exercise, $context, $workdir);
            if (null === $after->results) {
                $result->error('Solution : '.$runnerLabel.' n\'a produit aucun rapport. '.$after->failure(RunReport::GLOBAL, '(erreur fatale ?)'));

                return $result;
            }
            foreach ($exercise->objectives as $objective) {
                if (!\array_key_exists($objective->test, $after->results)) {
                    $result->error(sprintf('Solution : l\'objectif « %s » n\'a pas été exécuté.', $objective->test));
                }
            }
            foreach ($after->results as $test => $passed) {
                if (!$passed) {
                    $result->error('Solution : '.$this->describeFailure($test, $after));
                }
            }
            $result->objectivesBefore = array_map(static fn ($o) => $before->results[$o->test] ?? false, $exercise->objectives);
        } finally {
            if ($keepWorkdir) {
                $result->workdir = $workdir;
            } else {
                $this->workdirs->remove($workdir);
            }
        }

        return $result;
    }

    /**
     * Les étapes, une à une, sur le même projet : au départ d'une étape (solution de la précédente), ses objectifs ne
     * sont pas déjà tous atteints ; avec sa solution, ils le sont tous. Une étape rouge échoue donc avec le test de sa
     * solution, qui passe sur le code attendu (la solution de l'étape verte suivante).
     */
    private function checkSteps(Exercise $exercise, CheckContext $context, string $workdir, CheckResult $result): void
    {
        $runnerLabel = $context->environment->framework->testRunnerLabel();
        $previous = [];
        $first = null;
        foreach ($exercise->steps as $index => $step) {
            $label = sprintf('Étape « %s »', $step->id);
            $reference = StepPhase::Red === $step->phase ? $this->content->stepReferenceFiles($exercise, $index) : [];
            $before = $this->grade($exercise, $context, $workdir, $step, $reference);
            $first ??= $before;
            if (null === $before->results) {
                $result->error(sprintf('%s, au départ : %s n\'a produit aucun rapport. %s', $label, $runnerLabel, $before->failure(RunReport::GLOBAL, '(erreur fatale ?)')));
            } elseif (!array_filter($step->objectives, static fn (Objective $o) => true !== ($before->results[$o->test] ?? false))) {
                // Un refacto peut ne rien changer à ce que vérifient les tests : seul un objectif de forme le distingue.
                $message = sprintf('%s, au départ : tous ses objectifs sont déjà atteints, l\'étape n\'a rien à faire.', $label);
                StepPhase::Refactor === $step->phase ? $result->warning($message) : $result->error($message);
            }

            $solution = $this->content->stepSolutionFiles($exercise, $index);
            $changed = array_keys(array_filter($solution, static fn (string $content, string $path) => ($previous[$path] ?? null) !== $content, \ARRAY_FILTER_USE_BOTH));
            if (!$changed) {
                $result->error(sprintf('%s : aucune solution dans steps/%s/solution/.', $label, $step->id));
            }
            foreach ($changed as $path) {
                if (!$step->isEditable($path)) {
                    $result->warning(sprintf('%s : la solution modifie « %s », qui n\'est pas modifiable pendant l\'étape.', $label, $path));
                }
            }
            $this->workdirs->write($workdir, $solution);
            $this->workdirs->clearCaches($workdir, $context->environment->cacheDirs);
            $previous = $solution;

            $after = $this->grade($exercise, $context, $workdir, $step, $reference);
            if (null === $after->results) {
                $result->error(sprintf('%s, solution : %s n\'a produit aucun rapport. %s', $label, $runnerLabel, $after->failure(RunReport::GLOBAL, '(erreur fatale ?)')));

                return;
            }
            foreach ($step->objectives as $objective) {
                if (!\array_key_exists($objective->test, $after->results)) {
                    $result->error(sprintf('%s, solution : l\'objectif « %s » n\'a pas été exécuté.', $label, $objective->test));
                } elseif (!$after->results[$objective->test]) {
                    $result->error(sprintf('%s, solution : %s', $label, $this->describeFailure($objective->test, $after)));
                }
            }
        }
        // Le bilan « objectifs déjà validés au départ » porte sur ceux de l'exercice (la dernière étape).
        $result->objectivesBefore = array_map(static fn (Objective $o) => $first?->results[$o->test] ?? false, $exercise->objectives);
    }

    private function describeFailure(string $test, Grade $grade): string
    {
        return match (true) {
            Objective::OWN_TESTS === $test => 'ses propres tests ne passent pas — '.$grade->failure($test),
            Objective::OWN_TESTS_FAIL === $test => 'aucun test de l\'apprenant n\'échoue — '.$grade->failure($test),
            Objective::OWN_TESTS_REFERENCE === $test => 'ses tests ne passent pas sur le code attendu — '.$grade->failure($test),
            str_starts_with($test, Objective::MUTANT_PREFIX) => sprintf('le mutant « %s » n\'est détecté par aucun test — %s', substr($test, \strlen(Objective::MUTANT_PREFIX)), $grade->failure($test)),
            default => sprintf('le test %s échoue — %s', $test, $grade->failure($test)),
        };
    }

    /** @param array<string, string> $reference */
    private function grade(Exercise $exercise, CheckContext $context, string $workdir, ?ExerciseStep $step = null, array $reference = []): Grade
    {
        $report = $context->runner->run($context->environment, $workdir);

        return $this->grader->grade($exercise, $report, $context->runner, $context->environment, $workdir, $step, $reference);
    }
}
