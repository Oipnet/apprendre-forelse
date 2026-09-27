<?php

namespace App\Content\Check;

use App\Content\ContentRepository;
use App\Content\EnvironmentAssembler;
use App\Content\EnvironmentRegistry;
use App\Content\Exercise;
use App\Content\Objective;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Vérifie qu'un exercice est jouable :
 *  1. le contenu, par chaque ExerciseCheck (cohérence, version de Pratique, index de complétion) ;
 *  2. état de départ + tests → les tests ne doivent PAS être entièrement verts ;
 *  3. + solution → les tests doivent être entièrement verts, et chaque objectif doit avoir été exécuté.
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

        $workdir = $this->workdirs->create();
        try {
            $this->assembler->assemble($environment, $workdir);
            $this->workdirs->write($workdir, [...$context->starting, ...$context->tests]);

            $before = $this->grade($exercise, $context, $workdir);
            if (null === $before->results) {
                $result->error('État de départ : PHPUnit n\'a produit aucun rapport. '.$before->failure(RunReport::GLOBAL, '(erreur fatale ?)'));
            } elseif (!\in_array(false, $before->results, true)) {
                $result->error('État de départ : tous les tests passent déjà, l\'exercice n\'a rien à faire.');
            }

            $this->workdirs->write($workdir, $context->solution);
            $this->workdirs->clearCaches($workdir, $environment->cacheDirs);
            $after = $this->grade($exercise, $context, $workdir);
            if (null === $after->results) {
                $result->error('Solution : PHPUnit n\'a produit aucun rapport. '.$after->failure(RunReport::GLOBAL, '(erreur fatale ?)'));

                return $result;
            }
            foreach ($exercise->objectives as $objective) {
                if (!\array_key_exists($objective->test, $after->results)) {
                    $result->error(sprintf('Solution : l\'objectif « %s » n\'a pas été exécuté.', $objective->test));
                }
            }
            foreach ($after->results as $test => $passed) {
                if (!$passed) {
                    $result->error(match (true) {
                        Objective::OWN_TESTS === $test => 'Solution : ses propres tests ne passent pas — '.$after->failure($test),
                        str_starts_with($test, Objective::MUTANT_PREFIX) => sprintf('Solution : le mutant « %s » n\'est détecté par aucun test — %s', substr($test, \strlen(Objective::MUTANT_PREFIX)), $after->failure($test)),
                        default => sprintf('Solution : le test %s échoue — %s', $test, $after->failure($test)),
                    });
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

    private function grade(Exercise $exercise, CheckContext $context, string $workdir): Grade
    {
        $report = $context->runner->run($context->environment, $workdir);

        return $this->grader->grade($exercise, $report, $context->runner, $context->environment, $workdir);
    }
}
