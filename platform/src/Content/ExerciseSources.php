<?php

namespace App\Content;

use Symfony\Component\Finder\Finder;

/**
 * Les fichiers d'un exercice, lus dans son dossier : état de départ, solution, tests cachés. À ne pas confondre avec
 * Author\ExerciseFiles, qui lit et écrit un exercice depuis l'atelier.
 */
final readonly class ExerciseSources
{
    public function __construct(private ContentIndex $index)
    {
    }

    /**
     * État de départ de l'exercice : état final de l'exercice `base` (s'il y en a un), puis starter/.
     *
     * @return array<string, string> contenu par chemin relatif au projet
     */
    public function startingFiles(Exercise $exercise): array
    {
        $files = null === $exercise->base ? [] : $this->solvedFiles($this->baseOf($exercise));

        return [...$files, ...$this->read($exercise, 'starter')];
    }

    /** @return array<string, string> état de départ + solution */
    public function solvedFiles(Exercise $exercise): array
    {
        return [...$this->startingFiles($exercise), ...$this->solutionFiles($exercise)];
    }

    /**
     * Fichiers de solution seuls (jamais envoyés au navigateur). Exercice à étapes : les solutions de toutes ses
     * étapes, chacune par-dessus la précédente.
     *
     * @return array<string, string>
     */
    public function solutionFiles(Exercise $exercise): array
    {
        return $exercise->hasSteps() ? $this->stepSolutionFiles($exercise, \count($exercise->steps) - 1) : $this->read($exercise, 'solution');
    }

    /**
     * Les solutions des étapes, jusqu'à celle-ci comprise (steps/<id>/solution/), chacune par-dessus la précédente :
     * ce que l'apprenant a écrit, à la fin de l'étape.
     *
     * @return array<string, string>
     */
    public function stepSolutionFiles(Exercise $exercise, int $step): array
    {
        $files = [];
        foreach (\array_slice($exercise->steps, 0, max(0, $step + 1)) as $etape) {
            $files = [...$files, ...$this->read($exercise, 'steps/'.$etape->id.'/solution')];
        }

        return $files;
    }

    /**
     * Le code attendu d'une étape rouge, sur lequel les tests de l'apprenant doivent passer : l'état du projet à la fin
     * de l'étape suivante (verte), sans les tests de l'apprenant (ce sont les siens qu'on essaie). Envoyé au navigateur
     * avec l'exercice, comme les tests cachés : il ne donne que le code de l'étape verte suivante.
     *
     * @return array<string, string>
     */
    public function stepReferenceFiles(Exercise $exercise, int $step): array
    {
        // Seuls les fichiers que l'apprenant a pu changer : le reste du projet est déjà celui de l'exercice.
        $depart = array_filter($this->startingFiles($exercise), static fn (string $path) => $exercise->isEditable($path), \ARRAY_FILTER_USE_KEY);
        $files = [...$depart, ...$this->stepSolutionFiles($exercise, $step + 1)];
        $ownTests = $exercise->ownTests();

        return array_filter($files, static fn (string $path) => !\in_array($path, $ownTests, true), \ARRAY_FILTER_USE_KEY);
    }

    /** @return array<string, string> tests cachés, écrits dans tests/ du projet */
    public function testFiles(Exercise $exercise): array
    {
        $tests = [];
        foreach ($this->read($exercise, 'tests') as $path => $content) {
            $tests['tests/'.$path] = $content;
        }

        return $tests;
    }

    private function baseOf(Exercise $exercise): Exercise
    {
        return (null === $exercise->trackId ? null : $this->index->findExercise($exercise->trackId, (string) $exercise->base, $exercise->locale))
            ?? throw new ContentException(sprintf('Exercice « %s » : base « %s » introuvable.', $exercise->id, $exercise->base));
    }

    /**
     * Un dossier de l'exercice (starter, solution, tests) ; pour une traduction, ses fichiers traduits par-dessus.
     *
     * @return array<string, string>
     */
    private function read(Exercise $exercise, string $folder): array
    {
        $files = $this->readDirectory($exercise->directory.'/'.$folder);

        return null === $exercise->overlay ? $files : [...$files, ...$this->readDirectory($exercise->overlay.'/'.$folder)];
    }

    /** @return array<string, string> */
    private function readDirectory(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $files = [];
        foreach ((new Finder())->files()->in($directory)->ignoreDotFiles(false)->sortByName() as $file) {
            $files[str_replace('\\', '/', $file->getRelativePathname())] = $file->getContents();
        }

        return $files;
    }
}
