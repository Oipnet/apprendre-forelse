<?php

namespace App\Content\Check;

use App\Content\Exercise;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Une vérification du contenu d'un exercice, avant de lancer ses tests : chacune ajoute ses erreurs et
 * avertissements au résultat. L'ordre est celui des priorités (#[AsTaggedItem]).
 */
#[AutoconfigureTag]
interface ExerciseCheck
{
    public function check(Exercise $exercise, CheckContext $context, CheckResult $result): void;
}
