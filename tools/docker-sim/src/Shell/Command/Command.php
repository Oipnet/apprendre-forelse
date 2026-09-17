<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell\Command;

use Forelse\DockerSim\Shell\Interpreter;
use Forelse\DockerSim\Shell\Machine;
use Forelse\DockerSim\Shell\Result;

interface Command
{
    /** @return list<string> noms sous lesquels la commande est appelée */
    public function names(): array;

    /** @param list<string> $args arguments, sans le nom de la commande */
    public function run(string $name, array $args, Machine $machine, string $stdin, Interpreter $shell): Result;
}
