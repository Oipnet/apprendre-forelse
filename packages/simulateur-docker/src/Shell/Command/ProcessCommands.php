<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell\Command;

use Forelse\DockerSim\Shell\Interpreter;
use Forelse\DockerSim\Shell\Machine;
use Forelse\DockerSim\Shell\Result;

/** Processus, utilisateur et système (coreutils / busybox) : env, id, sh, xargs, ps, kill, su-exec… */
final class ProcessCommands extends CoreutilsCommands
{
    public function names(): array
    {
        return ['env', 'printenv', 'id', 'whoami', 'sleep', 'which', 'nproc', 'date', 'uname', 'hostname', 'xargs', 'sh', 'bash', 'ash', 'dash', 'ps', 'kill', 'nano', 'vi', 'vim', 'top', 'htop', 'tini', 'dumb-init', 'su-exec', 'gosu', 'su', 'sudo'];
    }

    /** @param list<string> $args */
    protected function dispatch(string $name, array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        return match ($name) {
            'env', 'printenv' => $this->env($name, $args, $m, $stdin, $sh),
            'id' => $this->id($args, $m),
            'whoami' => Result::ok($m->userName()."\n"),
            'sleep' => Result::ok('', min(5.0, (float) ($args[0] ?? 0))),
            'which' => $this->which($args, $m),
            'nproc' => Result::ok("4\n"),
            'date' => Result::ok(gmdate(isset($args[0]) && str_starts_with($args[0], '+') ? $this->dateFormat(substr($args[0], 1)) : 'D M j H:i:s \U\T\C Y')."\n"),
            'uname' => Result::ok(\in_array('-a', $args, true) ? "Linux {$m->hostname} 6.10.14-linuxkit #1 SMP x86_64 GNU/Linux\n" : (\in_array('-m', $args, true) ? "x86_64\n" : "Linux\n")),
            'hostname' => Result::ok($m->hostname."\n"),
            'xargs' => $this->xargs($args, $m, $stdin, $sh),
            'sh', 'bash', 'ash', 'dash' => $this->shell($args, $m, $stdin, $sh),
            'ps' => $this->ps($m),
            'kill' => $this->kill($args, $m),
            'nano', 'vi', 'vim', 'top', 'htop' => Result::error(1, sprintf("%s: pas de terminal interactif dans le simulateur (utilisez cat, sed ou echo)\n", $name)),
            'tini', 'dumb-init' => $this->wrapper($args, $m, $stdin, $sh),
            'su-exec', 'gosu' => $this->switchUser($name, $args, $m, $stdin, $sh),
            'su', 'sudo' => Result::error(1, $name === 'sudo' ? $sh->shellPrefix($m)."sudo: not found\n" : "su: must be run from a terminal\n"),
            default => throw new \LogicException(sprintf('%s : « %s » est déclarée sans implémentation.', self::class, $name)),
        };
    }

    /** @param list<string> $args */
    private function env(string $name, array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        $env = $m->env;
        unset($env['?']);
        // Les variables internes du simulateur ne regardent pas l'apprenant.
        $env = array_filter($env, static fn ($key) => !str_starts_with((string) $key, '__SIM_'), \ARRAY_FILTER_USE_KEY);
        if ($name === 'printenv' && $args !== []) {
            return isset($env[$args[0]]) ? Result::ok($env[$args[0]]."\n") : new Result(1);
        }
        $assignments = [];
        while ($args !== [] && (str_contains($args[0], '=') || $args[0][0] === '-')) {
            $arg = array_shift($args);
            if (str_contains($arg, '=')) {
                [$k, $v] = explode('=', $arg, 2);
                $assignments[$k] = $v;
            }
        }
        if ($args !== []) {
            $saved = $m->env;
            $m->env = $assignments + $m->env;
            try {
                return $sh->invoke($args, $m, $stdin);
            } finally {
                $m->env = $saved;
            }
        }
        $env = $assignments + $env;
        $env['HOSTNAME'] ??= $m->hostname;
        $env['HOME'] ??= $m->isRoot() ? '/root' : '/home/'.$m->userName();
        $out = '';
        foreach ($env as $key => $value) {
            $out .= $key.'='.$value."\n";
        }

        return Result::ok($out);
    }

    /** @param list<string> $args */
    private function id(array $args, Machine $m): Result
    {
        $operands = array_values(array_filter($args, static fn ($a) => $a !== '' && $a[0] !== '-'));
        $user = $operands[0] ?? $m->userName();
        if (isset($operands[0]) && !ctype_digit($user) && !isset($m->facts->users[$user])) {
            return Result::error(1, $m->facts->os === 'alpine' ? "id: unknown user {$user}\n" : "id: '{$user}': no such user\n");
        }
        $uid = ctype_digit($user) ? (int) $user : ($m->facts->users[$user] ?? 0);
        $name = ctype_digit($user) ? (array_search((int) $user, $m->facts->users, true) ?: null) : $user;
        // -n d'abord : « id -u -n » donne le nom, comme « id -un ».
        if (\in_array('-un', $args, true) || (\in_array('-u', $args, true) && \in_array('-n', $args, true))) {
            return Result::ok(($name ?? $uid)."\n");
        }
        if (\in_array('-u', $args, true)) {
            return Result::ok($uid."\n");
        }

        return Result::ok(sprintf("uid=%d(%s) gid=%d(%s) groups=%d(%s)\n", $uid, $name ?? $uid, $uid, $name ?? $uid, $uid, $name ?? $uid));
    }

    /** @param list<string> $args */
    private function which(array $args, Machine $m): Result
    {
        $out = '';
        $code = 0;
        foreach ($args as $arg) {
            if ($arg[0] === '-') {
                continue;
            }
            if ($m->facts->hasBinary($arg)) {
                $out .= (str_starts_with($arg, 'docker-php') || \in_array($arg, ['php', 'pecl', 'pear', 'phpize', 'php-config', 'php-fpm', 'composer', 'install-php-extensions'], true) ? '/usr/local/bin/' : '/usr/bin/').$arg."\n";
            } else {
                $code = 1;
            }
        }

        return new Result($code, $out);
    }

    /** @param list<string> $args */
    private function xargs(array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        $items = preg_split('/\s+/', trim($stdin), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $args = array_values(array_filter($args, static fn ($a) => !\in_array($a, ['-r', '--no-run-if-empty'], true)));
        if ($items === []) {
            return Result::ok();
        }

        return $sh->invoke([...($args ?: ['echo']), ...$items], $m);
    }

    /** @param list<string> $args */
    private function shell(array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        $script = null;
        $positional = [];
        for ($i = 0; $i < \count($args); ++$i) {
            if ($args[$i] === '-c') {
                $script = $args[++$i] ?? '';
                $positional = \array_slice($args, $i + 2);
                break;
            }
            if (preg_match('/^-[a-z]*c[a-z]*$/', $args[$i])) {
                if (str_contains($args[$i], 'e')) {
                    $m->errexit = true;
                }
                $script = $args[++$i] ?? '';
                $positional = \array_slice($args, $i + 2);
                break;
            }
            if ($args[$i][0] !== '-') {
                $content = $m->fs->read($m->path($args[$i]));
                if ($content === null) {
                    return Result::error(127, $sh->shellPrefix($m).'can\'t open \''.$args[$i]."': No such file or directory\n");
                }
                if (str_contains(strtok($content, "\n") ?: '', "\r")) {
                    return Result::error(2, $sh->shellPrefix($m).$args[$i].": line 2: syntax error: unexpected end of file (expecting \"then\")\n");
                }
                $script = $content;
                $positional = \array_slice($args, $i + 1);
                break;
            }
        }
        if ($script === null) {
            if ($m->mode === Machine::EXEC && $stdin === '') {
                // Shell interactif sans terminal (docker run sans -it) : se termine aussitôt.
                return Result::ok();
            }
            $script = $stdin;
        }

        return $sh->runScript($script, $m, $positional);
    }

    private function ps(Machine $m): Result
    {
        $command = $m->env['__SIM_MAIN_PROCESS'] ?? 'sh';

        return Result::ok("PID   USER     TIME  COMMAND\n    1 {$m->userName()}     0:00 {$command}\n   42 {$m->userName()}     0:00 ps\n");
    }

    /**
     * kill [-SIGNAL] PID… : seul le processus n° 1 (le conteneur) existe vraiment pour le simulateur.
     *
     * @param list<string> $args
     */
    private function kill(array $args, Machine $m): Result
    {
        $signal = 'TERM';
        $pids = [];
        for ($i = 0; $i < \count($args); ++$i) {
            $arg = $args[$i];
            if ($arg === '-s') {
                $signal = strtoupper((string) ($args[++$i] ?? 'TERM'));
            } elseif (preg_match('/^-(\d+|[A-Za-z]+)$/', $arg, $s)) {
                $signal = strtoupper(ctype_digit($s[1]) ? ([1 => 'HUP', 2 => 'INT', 3 => 'QUIT', 9 => 'KILL', 10 => 'USR1', 12 => 'USR2', 15 => 'TERM'][(int) $s[1]] ?? $s[1]) : $s[1]);
            } else {
                $pids[] = $arg;
            }
        }
        $signal = preg_replace('/^SIG/', '', $signal);
        if ($pids === []) {
            return Result::error(1, $m->facts->os === 'alpine' ? "kill: you need to specify whom to kill\n" : "kill: usage: kill [-s sigspec | -n signum | -sigspec] pid | jobspec ... or kill -l [sigspec]\n");
        }
        foreach ($pids as $pid) {
            if ($pid !== '1') {
                return Result::error(1, $m->facts->os === 'alpine' ? "kill: can't kill pid {$pid}: No such process\n" : "kill: ({$pid}) - No such process\n");
            }
        }
        if ($m->mode === Machine::EXEC) {
            $m->signals[] = 'kill:'.$signal;
        }

        return Result::ok();
    }

    private function dateFormat(string $format): string
    {
        return strtr($format, ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%H' => 'H', '%M' => 'i', '%S' => 's', '%s' => 'U', '%F' => 'Y-m-d', '%T' => 'H:i:s']);
    }

    /** @param list<string> $args */
    private function wrapper(array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        $args = array_values(array_filter($args, static fn ($a) => $a !== '--' && $a[0] !== '-'));

        return $args === [] ? Result::ok() : $sh->invoke($args, $m, $stdin);
    }

    /** @param list<string> $args */
    private function switchUser(string $name, array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        $user = array_shift($args) ?? '';
        if (!$m->isRoot()) {
            return Result::error(1, "{$name}: setgroups: Operation not permitted\n");
        }
        $userName = explode(':', $user)[0];
        if (!ctype_digit($userName) && !isset($m->facts->users[$userName])) {
            return Result::error(1, "{$name}: unknown user {$userName}\n");
        }
        $saved = $m->user;
        $m->user = $user;
        try {
            return $args === [] ? Result::ok() : $sh->invoke($args, $m, $stdin);
        } finally {
            $m->user = $saved;
        }
    }
}
