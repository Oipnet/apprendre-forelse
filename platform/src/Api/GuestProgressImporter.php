<?php

namespace App\Api;

use App\Entity\User;
use App\Service\ProgressService;

/**
 * Reprend la progression laissée dans le navigateur par un invité, sans écraser celle du compte : la Pratique (qui se
 * joue sans compte), et les exercices de parcours des versions où le premier chapitre se jouait sans compte.
 *
 * Le tri (exercice introuvable, fermé au compte) est celui de l'API ; ProgressService enregistre sans connaître l'API.
 */
final readonly class GuestProgressImporter
{
    public function __construct(
        private ExerciseLocator $exercises,
        private ExerciseAccessGuard $guard,
        private ProgressService $progress,
    ) {
    }

    /**
     * @param list<GuestProgressInput> $items
     *
     * @return int le nombre d'exercices repris
     */
    public function import(User $user, array $items): int
    {
        $imported = 0;
        foreach ($items as $item) {
            // On ne reprend que ce que le compte peut ouvrir, dans un parcours visible : une progression importée dans
            // un parcours en préparation le ferait apparaître (hasStarted). Un exercice de Pratique en préparation reste fermé.
            $exercise = $this->exercises->find($item->trackId, $item->exerciseId);
            if (!$exercise || !$this->guard->allows($user, $exercise) || $this->progress->find($user, $exercise)) {
                continue;
            }
            $this->progress->saveDraft($user, $exercise, $item->files, $item->hintsUsed);
            if ($item->completed) {
                $this->progress->complete($user, $exercise, $item->hintsUsed);
            }
            ++$imported;
        }

        return $imported;
    }
}
