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

        return [...$files, ...$this->readDirectory($exercise->directory.'/starter')];
    }

    /** @return array<string, string> état de départ + solution */
    public function solvedFiles(Exercise $exercise): array
    {
        return [...$this->startingFiles($exercise), ...$this->solutionFiles($exercise)];
    }

    /** @return array<string, string> fichiers de solution seuls (jamais envoyés au navigateur) */
    public function solutionFiles(Exercise $exercise): array
    {
        return $this->readDirectory($exercise->directory.'/solution');
    }

    /** @return array<string, string> tests cachés, écrits dans tests/ du projet */
    public function testFiles(Exercise $exercise): array
    {
        $tests = [];
        foreach ($this->readDirectory($exercise->directory.'/tests') as $path => $content) {
            $tests['tests/'.$path] = $content;
        }

        return $tests;
    }

    private function baseOf(Exercise $exercise): Exercise
    {
        return (null === $exercise->trackId ? null : $this->index->findExercise($exercise->trackId, (string) $exercise->base))
            ?? throw new ContentException(sprintf('Exercice « %s » : base « %s » introuvable.', $exercise->id, $exercise->base));
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
