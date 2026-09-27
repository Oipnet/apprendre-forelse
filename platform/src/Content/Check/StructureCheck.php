<?php

namespace App\Content\Check;

use App\Content\Exercise;
use App\Content\Objective;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** Cohérence du contenu : objectifs ↔ tests, fichiers éditables présents, mutants applicables. */
#[AsTaggedItem(priority: 30)]
final readonly class StructureCheck implements ExerciseCheck
{
    public function check(Exercise $exercise, CheckContext $context, CheckResult $result): void
    {
        $environment = $context->environment;
        $starting = $context->starting;
        $solution = $context->solution;
        if (!$context->tests && !$exercise->ownTests()) {
            $result->error('Aucun test dans tests/.');
        }
        if (!$solution) {
            $result->error('Aucune solution dans solution/.');
        }
        $testCode = implode("\n", $context->tests);
        foreach (array_filter($exercise->objectives, static fn (Objective $o) => $o->isHiddenTest()) as $objective) {
            if (!$context->runner->declares($testCode, $objective->test)) {
                $result->error(sprintf('Objectif « %s » : aucun %s de ce nom dans tests/.', $objective->test, $context->runner->testNoun()));
            }
        }
        // Un motif (migrations/*.php) désigne des fichiers qui n'existent pas encore : rien à vérifier.
        foreach ([...$exercise->editablePaths(), ...$exercise->readonly] as $path) {
            if (!isset($starting[$path]) && null === $environment->file($path)) {
                $result->error(sprintf('Fichier « %s » introuvable (ni dans starter/, ni dans une base, ni dans l\'environnement).', $path));
            }
        }
        // Le fichier ouvert au démarrage, quand seul un motif le couvre, doit faire partie de l'état de départ.
        if (!\in_array($exercise->open, [...$exercise->editablePaths(), ...$exercise->readonly], true) && !isset($starting[$exercise->open])) {
            $result->error(sprintf('« open » (%s) : ce fichier n\'existe pas au départ (ni dans starter/, ni dans une base).', $exercise->open));
        }
        foreach ($exercise->mutants as $mutant) {
            foreach ($mutant->changes as $change) {
                $depuisLEnvironnement = $environment->file($change['file']);
                $code = $solution[$change['file']] ?? $starting[$change['file']] ?? (null === $depuisLEnvironnement ? null : (string) file_get_contents($depuisLEnvironnement));
                if (null === $code || !str_contains($code, $change['search'])) {
                    $result->error(sprintf('Mutant « %s » : texte à remplacer introuvable dans %s (%s).', $mutant->id, $change['file'], $change['search']));
                }
            }
        }
        foreach (array_keys($solution) as $path) {
            if (!$exercise->isEditable($path)) {
                $result->warning(sprintf('La solution modifie « %s », qui n\'est pas éditable par l\'apprenant.', $path));
            }
        }
    }
}
