<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell\Command;

use Forelse\DockerSim\Shell\Interpreter;
use Forelse\DockerSim\Shell\Machine;
use Forelse\DockerSim\Shell\Result;

/** Commandes internes qui modifient l'état du shell : dossier courant, variables, options, paramètres. */
final class VariableBuiltins implements Command
{
    public function names(): array
    {
        return ['cd', 'set', 'export', 'local', 'unset', 'shift', 'read'];
    }

    public function run(string $name, array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        return match ($name) {
            'cd' => $this->cd($args, $m, $sh),
            'set' => $this->set($args, $m),
            'export', 'local' => $this->export($args, $m),
            'unset' => $this->unset($args, $m),
            'shift' => $this->shift($args, $m),
            'read' => $this->read($args, $m, $stdin),
            default => throw new \LogicException(sprintf('%s : « %s » est déclarée sans implémentation.', self::class, $name)),
        };
    }

    /** @param list<string> $args */
    private function cd(array $args, Machine $m, Interpreter $sh): Result
    {
        $target = $m->path($args[0] ?? ($m->env['HOME'] ?? '/root'));
        if (!$m->fs->isDir($target)) {
            return Result::error(2, $sh->shellPrefix($m).'cd: can\'t cd to '.($args[0] ?? '~').": No such file or directory\n");
        }
        $m->cwd = $target;
        $m->env['PWD'] = $target;

        return Result::ok();
    }

    /** @param list<string> $args */
    private function set(array $args, Machine $m): Result
    {
        foreach ($args as $arg) {
            if (preg_match('/^([-+])([a-z]+)$/', $arg, $flags)) {
                if (str_contains($flags[2], 'e')) {
                    $m->errexit = $flags[1] === '-';
                }
                if (str_contains($flags[2], 'x')) {
                    $m->xtrace = $flags[1] === '-';
                }
            } elseif ($arg === '--') {
                $m->positional = \array_slice($args, array_search('--', $args, true) + 1);
                break;
            }
        }

        return Result::ok();
    }

    /** @param list<string> $args */
    private function export(array $args, Machine $m): Result
    {
        foreach ($args as $arg) {
            if (str_contains($arg, '=')) {
                [$var, $value] = explode('=', $arg, 2);
                $m->env[$var] = $value;
            } elseif ($arg !== '-p') {
                $m->env[$arg] ??= '';
            }
        }

        return Result::ok();
    }

    /** @param list<string> $args */
    private function unset(array $args, Machine $m): Result
    {
        foreach ($args as $arg) {
            unset($m->env[$arg]);
        }

        return Result::ok();
    }

    /** @param list<string> $args */
    private function shift(array $args, Machine $m): Result
    {
        array_splice($m->positional, 0, (int) ($args[0] ?? 1));

        return Result::ok();
    }

    /** @param list<string> $args */
    private function read(array $args, Machine $m, string $stdin): Result
    {
        $line = strtok($stdin, "\n") ?: '';
        foreach (array_filter($args, static fn ($a) => $a[0] !== '-') as $var) {
            $m->env[$var] = $line;
        }

        return new Result($stdin === '' ? 1 : 0);
    }
}
