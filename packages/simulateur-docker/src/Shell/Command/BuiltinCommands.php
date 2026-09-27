<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell\Command;

use Forelse\DockerSim\Shell\Interpreter;
use Forelse\DockerSim\Shell\Machine;
use Forelse\DockerSim\Shell\Result;

/** Commandes internes du shell sans état : affichage, test / [, et celles qui ne font rien. */
final class BuiltinCommands implements Command
{
    public function names(): array
    {
        return ['echo', 'printf', 'pwd', 'true', ':', 'wait', 'trap', 'umask', 'ulimit', 'alias', 'hash', 'false', 'test', '['];
    }

    public function run(string $name, array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        return match ($name) {
            'echo' => $this->echo($args, $m),
            'printf' => $this->printf($args),
            'pwd' => Result::ok($m->cwd."\n"),
            'true', ':', 'wait', 'trap', 'umask', 'ulimit', 'alias', 'hash' => Result::ok(),
            'false' => new Result(1),
            'test', '[' => $this->testCommand($name, $args, $m, $sh),
            default => throw new \LogicException(sprintf('%s : « %s » est déclarée sans implémentation.', self::class, $name)),
        };
    }

    /** @param list<string> $args */
    private function echo(array $args, Machine $m): Result
    {
        $newline = true;
        $interpret = false;
        while ($args !== [] && preg_match('/^-[neE]+$/', $args[0])) {
            $flag = array_shift($args);
            $newline = $newline && !str_contains($flag, 'n');
            $interpret = $interpret || str_contains($flag, 'e');
        }
        $text = implode(' ', $args);
        if ($interpret || $m->facts->os !== 'alpine') {
            $text = stripcslashes(str_replace(['\\c'], [''], $text));
        }

        return Result::ok($text.($newline ? "\n" : ''));
    }

    /** @param list<string> $args */
    private function printf(array $args): Result
    {
        $format = array_shift($args) ?? '';
        $format = stripcslashes($format);
        try {
            $out = $args === [] ? $format : vsprintf(preg_replace('/%([^sdifxo%])/', '%%$1', $format) ?? $format, array_pad($args, substr_count($format, '%'), ''));
        } catch (\ValueError) {
            $out = $format;
        }

        return Result::ok($out);
    }

    /** @param list<string> $args */
    private function testCommand(string $name, array $args, Machine $m, Interpreter $sh): Result
    {
        if ($name === '[') {
            if (end($args) !== ']') {
                return Result::error(2, $sh->shellPrefix($m)."[: missing ]\n");
            }
            array_pop($args);
        }

        return new Result($this->test($args, $m, $sh) ? 0 : 1);
    }

    /** @param list<string> $args */
    private function test(array $args, Machine $m, Interpreter $sh): bool
    {
        if ($args === []) {
            return false;
        }
        if ($args[0] === '!') {
            return !$this->test(\array_slice($args, 1), $m, $sh);
        }
        foreach (['-o', '-a'] as $logical) {
            $position = array_search($logical, $args, true);
            if ($position !== false && $position > 0) {
                $left = $this->test(\array_slice($args, 0, $position), $m, $sh);
                $right = $this->test(\array_slice($args, $position + 1), $m, $sh);

                return $logical === '-o' ? $left || $right : $left && $right;
            }
        }
        if (\count($args) === 1) {
            return $args[0] !== '';
        }
        if (\count($args) === 2) {
            $path = $m->path($args[1]);

            return match ($args[0]) {
                '-z' => $args[1] === '',
                '-n' => $args[1] !== '',
                '-f' => $m->fs->isFile($path),
                '-d' => $m->fs->isDir($path),
                '-e' => $m->fs->exists($path),
                '-s' => $m->fs->isFile($path) && $m->fs->size($path) > 0,
                '-x' => $m->fs->exists($path) && ($m->fs->mode($path) & 0111) !== 0,
                '-r' => $m->fs->exists($path),
                '-w' => $m->fs->exists($path) && $sh->canWrite($m, $path),
                '-L', '-h' => false,
                default => false,
            };
        }
        [$left, $op, $right] = [$args[0], $args[1], $args[2] ?? ''];

        return match ($op) {
            '=', '==' => $left === $right,
            '!=' => $left !== $right,
            '-eq' => (int) $left === (int) $right,
            '-ne' => (int) $left !== (int) $right,
            '-lt' => (int) $left < (int) $right,
            '-le' => (int) $left <= (int) $right,
            '-gt' => (int) $left > (int) $right,
            '-ge' => (int) $left >= (int) $right,
            default => false,
        };
    }
}
