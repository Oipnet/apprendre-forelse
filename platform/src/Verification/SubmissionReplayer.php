<?php

namespace App\Verification;

use App\Content\Check\MutantGrader;
use App\Content\Check\ProjectWorkdir;
use App\Content\Check\RunReport;
use App\Content\Check\TestRunners;
use App\Content\ContentException;
use App\Content\ContentRepository;
use App\Content\EnvironmentAssembler;
use App\Content\EnvironmentRegistry;
use App\Entity\VerificationStatus;

/**
 * Rejoue une réussite : le projet de l'exercice reconstitué, le code soumis par-dessus, les tests cachés en dernier
 * (le code de l'apprenant ne peut pas les remplacer), puis les mêmes tests et la même notation que le navigateur et
 * `content:check` (voir ExerciseChecker). Réussie si chaque objectif passe, comme dans le playground.
 *
 * Exécute le code de l'apprenant : uniquement dans le service « verificateur » (voir VerifierCommand), jamais là où
 * vivent la base et les secrets.
 */
final readonly class SubmissionReplayer
{
    public function __construct(
        private ContentRepository $content,
        private EnvironmentRegistry $environments,
        private TestRunners $runners,
        private MutantGrader $grader,
        private ProjectWorkdir $workdirs = new ProjectWorkdir(),
        private EnvironmentAssembler $assembler = new EnvironmentAssembler(),
    ) {
    }

    public function replay(VerificationRequest $request): VerificationResult
    {
        $exercise = null === $request->trackId
            ? $this->content->findPractice($request->exerciseId)?->exercise
            : $this->content->findExercise($request->trackId, $request->exerciseId);
        if (null === $exercise) {
            return $this->unverifiable($request, 'Exercice introuvable dans les packs (retiré ou renommé).');
        }
        $files = array_filter($request->files, static fn (string $path) => $exercise->isEditable($path), \ARRAY_FILTER_USE_KEY);
        if ([] === $files) {
            return $this->unverifiable($request, 'Aucun fichier éditable dans le code soumis.');
        }
        if (!$this->environments->has($exercise->environment)) {
            return $this->unverifiable($request, sprintf('Environnement « %s » absent.', $exercise->environment));
        }
        try {
            $environment = $this->environments->get($exercise->environment);
            $runner = $this->runners->for($environment->framework);
        } catch (ContentException $e) {
            return $this->unverifiable($request, $e->getMessage());
        }
        if (null !== ($unavailable = $runner->unavailable($environment))) {
            return $this->unverifiable($request, $unavailable);
        }

        $workdir = $this->workdirs->create();
        try {
            $this->assembler->assemble($environment, $workdir);
            $this->workdirs->write($workdir, [...$this->content->startingFiles($exercise), ...$files, ...$this->content->testFiles($exercise)]);
            $grade = $this->grader->grade($exercise, $runner->run($environment, $workdir), $runner, $environment, $workdir);
        } finally {
            $this->workdirs->remove($workdir);
        }

        if (null === $grade->results) {
            return new VerificationResult($request->progressId, VerificationStatus::Failed, 'Les tests n\'ont rien rapporté : '.$grade->failure(RunReport::GLOBAL, '(erreur fatale ?)'));
        }
        foreach ($exercise->objectives as $objective) {
            if (true !== ($grade->results[$objective->test] ?? false)) {
                return new VerificationResult($request->progressId, VerificationStatus::Failed, sprintf('Objectif « %s » non atteint : %s', $objective->label, $grade->failure($objective->test, 'test absent du rapport.')));
            }
        }

        return new VerificationResult($request->progressId, VerificationStatus::Passed);
    }

    private function unverifiable(VerificationRequest $request, string $why): VerificationResult
    {
        return new VerificationResult($request->progressId, VerificationStatus::Unverifiable, $why);
    }
}
