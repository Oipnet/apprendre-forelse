<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Cli;

/** Un domaine de la ligne de commande docker (images, conteneurs, réseaux…), appelé par Application. */
interface CliCommand
{
    /** @return list<string> commandes de premier niveau prises en charge (docker <nom> …) */
    public function names(): array;

    /** @param list<string> $argv arguments après le nom de la commande */
    public function run(string $name, array $argv, Output $out): int;
}
