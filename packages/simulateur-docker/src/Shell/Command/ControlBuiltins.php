<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell\Command;

use Forelse\DockerSim\Shell\ExitSignal;
use Forelse\DockerSim\Shell\Interpreter;
use Forelse\DockerSim\Shell\Machine;
use Forelse\DockerSim\Shell\Result;

/** Commandes internes qui pilotent le shell lui-même : sortie, recherche de commande, exec, eval, source. */
final class ControlBuiltins implements Command
{
    public function names(): array
    {
        return ['exit', 'return', 'command', 'type', 'exec', 'eval', '.', 'source'];
    }

    public function run(string $name, array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        return match ($name) {
            'exit', 'return' => throw new ExitSignal((int) ($args[0] ?? ($m->env['?'] ?? 0)), ''),
            'command', 'type' => $this->command($name, $args, $m, $stdin, $sh),
            'exec' => $this->exec($args, $m, $stdin, $sh),
            'eval' => $sh->runScript(implode(' ', $args), $m, $m->positional, $stdin),
            '.', 'source' => $this->source($args, $m, $stdin, $sh),
            default => throw new \LogicException(sprintf('%s : « %s » est déclarée sans implémentation.', self::class, $name)),
        };
    }

    /** @param list<string> $args */
    private function command(string $name, array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        $query = array_values(array_filter($args, static fn ($a) => $a[0] !== '-'));
        if ($query === []) {
            return Result::ok();
        }
        if (\in_array('-v', $args, true) || $name === 'type') {
            $builtin = $sh->isBuiltin($query[0]);
            $found = $builtin || $m->facts->hasBinary($query[0]);

            return $found ? Result::ok(($name === 'type' ? $query[0].' is ' : '').($builtin ? $query[0] : '/usr/bin/'.$query[0])."\n") : new Result(1);
        }

        return $sh->invoke($query, $m, $stdin);
    }

    /** @param list<string> $args */
    private function exec(array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        if ($args === []) {
            return Result::ok();
        }
        // Dans un script d'entrée, « exec "$@" » remplace le shell par la commande.
        if ($m->mode === Machine::EXEC && $sh->depth() <= 2) {
            $m->execTarget = $args;

            throw new ExitSignal(0, '');
        }

        return $sh->invoke($args, $m, $stdin);
    }

    /** @param list<string> $args */
    private function source(array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        $content = $m->fs->read($m->path($args[0] ?? ''));
        if ($content === null) {
            return Result::error(2, $sh->shellPrefix($m).'.: '.($args[0] ?? '').": not found\n");
        }
        $sh->run($content, $m, $stdin);

        return Result::ok();
    }
}
