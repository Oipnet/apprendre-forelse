<?php

namespace App\Defense;

/** Une règle des soutenances refuse l'opération : rien n'a été enregistré. Le message s'affiche tel quel. */
final class DefenseRuleViolation extends \DomainException
{
}
