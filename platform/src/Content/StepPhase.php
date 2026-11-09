<?php

namespace App\Content;

/** La phase d'une étape d'exercice (voir ExerciseStep) : la boucle rouge, vert, refacto du TDD. */
enum StepPhase: string
{
    /** Écrire un test qui échoue : seuls les tests sont modifiables. */
    case Red = 'red';
    /** Le faire passer : seul le code est modifiable, les tests sont verrouillés. */
    case Green = 'green';
    /** Améliorer le code sans changer ce qu'il fait : les tests restent verrouillés, et verts. */
    case Refactor = 'refactor';
}
