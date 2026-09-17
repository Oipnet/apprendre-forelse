<?php

namespace App\Content\Check;

use App\Content\Exercise;

final class CheckResult
{
    /** @var list<string> */
    public array $errors = [];
    /** @var list<string> */
    public array $warnings = [];
    /** @var list<bool> état de chaque objectif avant résolution (true = déjà validé) */
    public array $objectivesBefore = [];
    /** Projet reconstitué, conservé pour inspection (option --keep). */
    public ?string $workdir = null;

    public function __construct(public readonly Exercise $exercise)
    {
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    public function warning(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function isOk(): bool
    {
        return !$this->errors;
    }
}
