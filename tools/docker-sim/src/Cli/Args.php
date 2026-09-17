<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Cli;

/**
 * Analyse des options à la manière de la CLI Docker (cobra/pflag) : -d, -dit, -p 8080:80, -p8080:80,
 * --name x, --name=x, options répétables. L'analyse s'arrête au premier argument positionnel quand
 * la commande le demande (docker run IMAGE COMMANDE…).
 */
final class Args
{
    /** @var array<string, mixed> */
    public array $options = [];
    /** @var list<string> */
    public array $positional = [];

    /**
     * @param list<string>                                                   $argv
     * @param array<string, array{0: string, 1: bool, 2?: bool}>              $spec  « d » ou « detach » => [nom, attend une valeur, répétable]
     */
    public static function parse(array $argv, array $spec, string $command, bool $stopAtPositional = false): self
    {
        $args = new self();
        $count = \count($argv);
        for ($i = 0; $i < $count; ++$i) {
            $arg = $argv[$i];
            if ($arg === '--') {
                array_push($args->positional, ...\array_slice($argv, $i + 1));
                break;
            }
            if (str_starts_with($arg, '--') && \strlen($arg) > 2) {
                [$name, $inline] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
                if (!isset($spec[$name])) {
                    throw new UsageError(sprintf("unknown flag: --%s\nSee 'docker %s --help'.", $name, $command));
                }
                [$key, $takesValue] = $spec[$name];
                $value = true;
                if ($takesValue) {
                    $value = $inline ?? ($argv[++$i] ?? throw new UsageError(sprintf("flag needs an argument: --%s\nSee 'docker %s --help'.", $name, $command)));
                } elseif ($inline !== null) {
                    $value = !\in_array(strtolower($inline), ['false', '0'], true);
                }
                $args->set($key, $value, $spec[$name][2] ?? false);
                continue;
            }
            if (str_starts_with($arg, '-') && \strlen($arg) > 1 && !is_numeric($arg)) {
                $letters = substr($arg, 1);
                for ($j = 0; $j < \strlen($letters); ++$j) {
                    $letter = $letters[$j];
                    if (!isset($spec[$letter])) {
                        throw new UsageError(sprintf("unknown shorthand flag: '%s' in -%s\nSee 'docker %s --help'.", $letter, $letters, $command));
                    }
                    [$key, $takesValue] = $spec[$letter];
                    if ($takesValue) {
                        $rest = substr($letters, $j + 1);
                        $value = $rest !== '' ? ltrim($rest, '=') : ($argv[++$i] ?? throw new UsageError(sprintf("flag needs an argument: '%s' in -%s\nSee 'docker %s --help'.", $letter, $letter, $command)));
                        $args->set($key, $value, $spec[$letter][2] ?? false);
                        break;
                    }
                    $args->set($key, true, false);
                }
                continue;
            }
            $args->positional[] = $arg;
            if ($stopAtPositional) {
                array_push($args->positional, ...\array_slice($argv, $i + 1));
                break;
            }
        }

        return $args;
    }

    private function set(string $key, mixed $value, bool $repeatable): void
    {
        if ($repeatable) {
            $this->options[$key][] = $value;
        } else {
            $this->options[$key] = $value;
        }
    }

    public function has(string $key): bool
    {
        return !empty($this->options[$key]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /** @return list<string> */
    public function all(string $key): array
    {
        return array_map('strval', (array) ($this->options[$key] ?? []));
    }
}
