<?php

namespace App\Cohort;

/** Une règle de la cohorte refuse le changement : rien n'a été enregistré. Le message s'affiche tel quel. */
final class CohortRuleViolation extends \DomainException
{
}
