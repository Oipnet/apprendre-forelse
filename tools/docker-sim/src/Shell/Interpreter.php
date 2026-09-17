<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell;

use Forelse\DockerSim\Fs\Path;
use Forelse\DockerSim\Shell\Command;
use Forelse\DockerSim\Shell\Command\Command as ShellCommand;

/**
 * Exécute un script shell sur une Machine : expansion des variables et des motifs, redirections,
 * pipelines, listes && / ||, set -e, structures de contrôle. Les commandes elles-mêmes sont
 * simulées (Command) ; une commande absente de l'image répond « not found » (code 127).
 */
final class Interpreter
{
    /** Commandes internes du shell : toujours disponibles, même sans binaire. */
    private const BUILTINS = ['echo', 'printf', 'cd', 'pwd', 'set', 'export', 'unset', 'exit', 'true', 'false', ':', 'test', '[', 'exec', 'command', 'type', 'read', 'shift', 'return', 'local', 'eval', '.', 'source', 'trap', 'umask', 'wait', 'ulimit', 'alias', 'hash'];

    /** @var array<string, ShellCommand> */
    private array $commands = [];
    private Parser $parser;
    private int $depth = 0;

    /** @param iterable<ShellCommand> $commands */
    public function __construct(iterable $commands = [])
    {
        $this->parser = new Parser();
        foreach ($commands as $command) {
            $this->register($command);
        }
    }

    public static function create(): self
    {
        return new self([
            new Command\CoreCommands(),
            new Command\PackageCommands(),
            new Command\PhpCommands(),
            new Command\ComposerCommand(),
            new Command\NetworkCommands(),
            new Command\SystemCommands(),
        ]);
    }

    public function register(ShellCommand $command): void
    {
        foreach ($command->names() as $name) {
            $this->commands[$name] = $command;
        }
    }

    /** Exécute un script ; la sortie (stdout et stderr mêlés, dans l'ordre) s'ajoute à $machine->output. */
    public function run(string $script, Machine $machine, string $stdin = ''): int
    {
        try {
            $ast = $this->parser->parse($script);
        } catch (SyntaxError $e) {
            $machine->output .= $this->shellPrefix($machine).$e->getMessage()."\n";

            return 2;
        }
        try {
            [$code, $out] = $this->execList($ast, $machine, $stdin);
        } catch (ExitSignal $exit) {
            $machine->output .= $exit->output;

            return $exit->exitCode;
        }
        $machine->output .= $out;

        return $code;
    }

    /** Exécute une commande déjà découpée (forme exec : ["php", "-v"]), sans passer par un shell. */
    public function runArgv(array $argv, Machine $machine, string $stdin = ''): int
    {
        try {
            $result = $this->invoke($argv, $machine, $stdin, exec: true);
        } catch (ExitSignal $exit) {
            $machine->output .= $exit->output;

            return $exit->exitCode;
        }
        $machine->output .= $result->stdout.$result->stderr;
        $machine->elapsed += $result->seconds;

        return $result->code;
    }

    /** Le préfixe des erreurs du shell : « /bin/sh: 1: » (dash, Debian) ou « /bin/sh: » (busybox, Alpine). */
    public function shellPrefix(Machine $machine): string
    {
        return $machine->facts->os === 'alpine' ? '/bin/sh: ' : '/bin/sh: 1: ';
    }

    // --- Exécution ------------------------------------------------------------------------

    /** @return array{0:int,1:string} */
    private function execList(array $list, Machine $m, string $stdin): array
    {
        $code = 0;
        $output = '';
        foreach ($list as $andor) {
            try {
                [$code, $out, $checked] = $this->execAndOr($andor, $m, $stdin);
            } catch (ExitSignal $exit) {
                // exit, exec ou set -e : la sortie déjà produite ne doit pas se perdre.
                throw new ExitSignal($exit->exitCode, $output.$exit->output);
            }
            $output .= $out;
            if ($code !== 0 && $m->errexit && !$checked) {
                throw new ExitSignal($code, $output);
            }
        }

        return [$code, $output];
    }

    /**
     * Liste && / || : set -e ne s'applique qu'à l'échec de la dernière commande de la liste
     * (POSIX), pas à celui d'une commande suivie de && ou ||.
     *
     * @return array{0:int,1:string,2:bool} code, sortie, échec ignoré par set -e
     */
    private function execAndOr(array $andor, Machine $m, string $stdin): array
    {
        $code = 0;
        $output = '';
        $lastExecuted = -1;
        $negated = false;
        $count = \count($andor['parts']);
        foreach ($andor['parts'] as $index => [$op, $pipeline]) {
            if (($op === '&&' && $code !== 0) || ($op === '||' && $code === 0)) {
                continue;
            }
            try {
                [$code, $out] = $this->execPipeline($pipeline, $m, $stdin);
            } catch (ExitSignal $exit) {
                throw new ExitSignal($exit->exitCode, $output.$exit->output);
            }
            $output .= $out;
            $lastExecuted = $index;
            $negated = $pipeline['negate'];
        }

        return [$code, $output, $lastExecuted !== $count - 1 || $negated];
    }

    /** @return array{0:int,1:string} */
    private function execPipeline(array $pipeline, Machine $m, string $stdin): array
    {
        $input = $stdin;
        $code = 0;
        $visible = '';
        $count = \count($pipeline['commands']);
        foreach ($pipeline['commands'] as $index => $command) {
            try {
                [$code, $stdout, $stderr] = $this->execCommand($command, $m, $input);
            } catch (ExitSignal $exit) {
                throw new ExitSignal($exit->exitCode, $visible.$exit->output);
            }
            if ($index < $count - 1) {
                $input = $stdout;
                $visible .= $stderr;
            } else {
                // La sortie normale d'abord, puis les erreurs : l'ordre qu'on lit dans un terminal.
                $visible .= $stdout.$stderr;
            }
        }
        if ($pipeline['negate']) {
            $code = $code === 0 ? 1 : 0;
        }

        return [$code, $visible];
    }

    /** @return array{0:int,1:string,2:string} code, stdout, stderr */
    private function execCommand(array $command, Machine $m, string $stdin): array
    {
        if (++$this->depth > 60) {
            --$this->depth;

            return [2, '', $this->shellPrefix($m)."recursion too deep\n"];
        }
        try {
            [$stdin, $redirects] = $this->prepareRedirects($command['redirects'] ?? [], $m, $stdin);
            $result = match ($command['type']) {
                'simple' => $this->execSimple($command, $m, $stdin),
                'if' => $this->execIf($command, $m, $stdin),
                'for' => $this->execFor($command, $m, $stdin),
                'while' => $this->execWhile($command, $m, $stdin),
                'case' => $this->execCase($command, $m, $stdin),
                'group' => $this->execGroup($command, $m, $stdin),
                default => [0, '', ''],
            };

            return $this->applyRedirects($result, $redirects, $m);
        } finally {
            --$this->depth;
        }
    }

    /** @return array{0:int,1:string,2:string} */
    private function execSimple(array $command, Machine $m, string $stdin): array
    {
        $argv = [];
        foreach ($command['words'] as $word) {
            array_push($argv, ...$this->expandWord($word, $m));
        }
        $assignments = [];
        foreach ($command['assign'] as [$name, $word]) {
            $assignments[$name] = $this->expandText($word, $m);
        }
        if ($argv === []) {
            foreach ($assignments as $name => $value) {
                $m->env[$name] = $value;
            }

            return [0, '', ''];
        }
        if ($m->xtrace) {
            $trace = '+ '.implode(' ', $argv)."\n";
        }
        $saved = $m->env;
        foreach ($assignments as $name => $value) {
            $m->env[$name] = $value;
        }
        try {
            $result = $this->invoke($argv, $m, $stdin);
        } finally {
            if ($assignments !== [] && !\in_array($argv[0], ['export'], true)) {
                foreach ($assignments as $name => $value) {
                    if (\array_key_exists($name, $saved)) {
                        $m->env[$name] = $saved[$name];
                    } else {
                        unset($m->env[$name]);
                    }
                }
            }
        }
        $m->elapsed += $result->seconds;
        $m->env['?'] = (string) $result->code;

        return [$result->code, $result->stdout, ($trace ?? '').$result->stderr];
    }

    /**
     * Appelle une commande : interne, simulée si le binaire existe dans l'image, ou script de l'image.
     *
     * @param list<string> $argv
     */
    public function invoke(array $argv, Machine $m, string $stdin = '', bool $exec = false): Result
    {
        $name = $argv[0];
        $args = \array_slice($argv, 1);
        if (!$exec && \in_array($name, self::BUILTINS, true)) {
            return $this->builtin($name, $args, $m, $stdin);
        }
        if ($exec && \in_array($name, ['echo', 'true', 'false', 'test', '['], true)) {
            return $this->builtin($name, $args, $m, $stdin);
        }

        // Chemin explicite (./docker-entrypoint.sh, /usr/local/bin/x) ou script installé dans le PATH.
        $scriptPath = null;
        if (str_contains($name, '/')) {
            $scriptPath = $m->path($name);
        } elseif (!$m->facts->hasBinary($name)) {
            foreach (explode(':', $m->env['PATH'] ?? '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin') as $dir) {
                if ($m->fs->isFile(rtrim($dir, '/').'/'.$name)) {
                    $scriptPath = rtrim($dir, '/').'/'.$name;
                    break;
                }
            }
        }
        if ($scriptPath !== null && !($m->facts->hasBinary(basename($scriptPath)) && !$m->fs->isFile($scriptPath))) {
            return $this->runScriptFile($scriptPath, $name, $args, $m, $stdin, $exec);
        }

        if (!$m->facts->hasBinary($name)) {
            return $this->notFound($name, $m, $exec);
        }
        if (isset($this->commands[$name])) {
            return $this->commands[$name]->run($name, $args, $m, $stdin, $this);
        }

        // Binaire présent mais non simulé : il « réussit » sans effet.
        $m->note(sprintf('« %s » n\'est pas simulé : la commande est considérée comme réussie.', $name));

        return Result::ok('', 0.2);
    }

    private function notFound(string $name, Machine $m, bool $exec): Result
    {
        if ($exec) {
            return Result::error(127, sprintf('exec: "%s": executable file not found in $PATH', $name)."\n");
        }

        return Result::error(127, $this->shellPrefix($m).$name.": not found\n");
    }

    /** @param list<string> $args */
    private function runScriptFile(string $path, string $name, array $args, Machine $m, string $stdin, bool $exec): Result
    {
        if (!$m->fs->exists($path)) {
            return $exec
                ? Result::error(127, sprintf('exec: "%s": stat %s: no such file or directory', $name, $path)."\n")
                : Result::error(127, $this->shellPrefix($m).$name.": not found\n");
        }
        if ($m->fs->isDir($path)) {
            return Result::error(126, $this->shellPrefix($m).$name.": Permission denied\n");
        }
        if (($m->fs->mode($path) & 0111) === 0) {
            return $exec
                ? Result::error(126, sprintf('exec: "%s": permission denied', $name)."\n")
                : Result::error(126, $this->shellPrefix($m).$name.": Permission denied\n");
        }
        $content = (string) $m->fs->read($path);
        $firstLine = strtok($content, "\n") ?: '';
        if (str_ends_with($firstLine, "\r")) {
            // Fin de ligne Windows : le noyau cherche l'interpréteur « /bin/sh\r ».
            return $exec
                ? Result::error(127, sprintf('exec %s: no such file or directory', $path)."\n")
                : Result::error(127, $this->shellPrefix($m).$name.": not found\n");
        }
        if (preg_match('#^\#!\s*(\S+)(?:\s+(\S+))?#', $firstLine, $shebang)) {
            $interpreter = basename($shebang[1]) === 'env' ? ($shebang[2] ?? 'sh') : basename($shebang[1]);
            if (\in_array($interpreter, ['php'], true)) {
                return $this->invoke(['php', $path, ...$args], $m, $stdin);
            }
            if (!\in_array($interpreter, ['sh', 'bash', 'ash', 'dash'], true)) {
                return Result::ok('', 0.1);
            }
            if ($interpreter === 'bash' && !$m->facts->hasBinary('bash')) {
                return $exec
                    ? Result::error(127, sprintf('exec %s: no such file or directory', $path)."\n")
                    : Result::error(127, $this->shellPrefix($m).$name.": not found\n");
            }
        }

        return $this->runScript($content, $m, $args, $stdin);
    }

    /** Exécute un script (fichier ou sh -c) avec ses paramètres positionnels, dans un sous-shell. */
    public function runScript(string $content, Machine $m, array $positional = [], string $stdin = ''): Result
    {
        $savedPositional = $m->positional;
        $savedErrexit = $m->errexit;
        $savedXtrace = $m->xtrace;
        $savedCwd = $m->cwd;
        $savedOutput = $m->output;
        $m->positional = $positional;
        $m->output = '';
        $start = $m->elapsed;
        try {
            $code = $this->run($content, $m, $stdin);
        } finally {
            $out = $m->output;
            $m->output = $savedOutput;
            $m->positional = $savedPositional;
            $m->errexit = $savedErrexit;
            $m->xtrace = $savedXtrace;
            $m->cwd = $savedCwd;
        }
        $seconds = $m->elapsed - $start;
        $m->elapsed = $start;

        return new Result($code, $out, '', $seconds);
    }

    /** @return array{0:int,1:string,2:string} */
    private function execIf(array $command, Machine $m, string $stdin): array
    {
        $output = '';
        foreach ($command['clauses'] as [$cond, $body]) {
            $errexit = $m->errexit;
            $m->errexit = false;
            try {
                [$code, $out] = $this->execList($cond, $m, $stdin);
            } finally {
                $m->errexit = $errexit;
            }
            $output .= $out;
            if ($code === 0) {
                try {
                    [$code, $out] = $this->execList($body, $m, $stdin);
                } catch (ExitSignal $exit) {
                    throw new ExitSignal($exit->exitCode, $output.$exit->output);
                }

                return [$code, $output.$out, ''];
            }
        }
        if ($command['else'] !== null) {
            [$code, $out] = $this->execList($command['else'], $m, $stdin);

            return [$code, $output.$out, ''];
        }

        return [0, $output, ''];
    }

    /** @return array{0:int,1:string,2:string} */
    private function execFor(array $command, Machine $m, string $stdin): array
    {
        $values = [];
        if ($command['words'] === null) {
            $values = $m->positional;
        } else {
            foreach ($command['words'] as $word) {
                array_push($values, ...$this->expandWord($word, $m));
            }
        }
        $output = '';
        $code = 0;
        foreach ($values as $value) {
            $m->env[$command['var']] = $value;
            try {
                [$code, $out] = $this->execList($command['body'], $m, $stdin);
            } catch (ExitSignal $exit) {
                throw new ExitSignal($exit->exitCode, $output.$exit->output);
            }
            $output .= $out;
        }

        return [$code, $output, ''];
    }

    /** @return array{0:int,1:string,2:string} */
    private function execWhile(array $command, Machine $m, string $stdin): array
    {
        $output = '';
        $code = 0;
        // Pas de vraie attente : une boucle d'attente (« until pg_isready ») s'arrête vite.
        for ($i = 0; $i < 30; ++$i) {
            $errexit = $m->errexit;
            $m->errexit = false;
            try {
                [$condCode, $out] = $this->execList($command['cond'], $m, $stdin);
            } finally {
                $m->errexit = $errexit;
            }
            $output .= $out;
            $continue = $command['until'] ? $condCode !== 0 : $condCode === 0;
            if (!$continue) {
                break;
            }
            [$code, $out] = $this->execList($command['body'], $m, $stdin);
            $output .= $out;
            if ($i === 29) {
                $m->note('Boucle interrompue par le simulateur au bout de 30 tours.');
            }
        }

        return [$code, $output, ''];
    }

    /** @return array{0:int,1:string,2:string} */
    private function execCase(array $command, Machine $m, string $stdin): array
    {
        $subject = $this->expandText($command['word'], $m);
        foreach ($command['items'] as [$patterns, $body]) {
            foreach ($patterns as $pattern) {
                $text = $this->expandText($pattern, $m);
                if (fnmatch($text, $subject)) {
                    [$code, $out] = $this->execList($body, $m, $stdin);

                    return [$code, $out, ''];
                }
            }
        }

        return [0, '', ''];
    }

    /** @return array{0:int,1:string,2:string} */
    private function execGroup(array $command, Machine $m, string $stdin): array
    {
        if ($command['subshell']) {
            $env = $m->env;
            $cwd = $m->cwd;
            try {
                [$code, $out] = $this->execList($command['body'], $m, $stdin);
            } finally {
                $m->env = $env;
                $m->cwd = $cwd;
            }

            return [$code, $out, ''];
        }
        [$code, $out] = $this->execList($command['body'], $m, $stdin);

        return [$code, $out, ''];
    }

    // --- Redirections ---------------------------------------------------------------------

    /** @return array{0:string,1:list<array{0:string,1:string}>} */
    private function prepareRedirects(array $redirects, Machine $m, string $stdin): array
    {
        $prepared = [];
        foreach ($redirects as [$op, $word]) {
            $target = $this->expandText($word, $m);
            if ($op === '<') {
                $content = $m->fs->read($m->path($target));
                $stdin = $content ?? '';
                continue;
            }
            if ($op === '<<') {
                // Heredoc : le contenu est fourni par le Dockerfile (Instruction::$heredoc) via stdin.
                continue;
            }
            $prepared[] = [$op, $target];
        }

        return [$stdin, $prepared];
    }

    /**
     * @param array{0:int,1:string,2:string}       $result
     * @param list<array{0:string,1:string}> $redirects
     *
     * @return array{0:int,1:string,2:string}
     */
    private function applyRedirects(array $result, array $redirects, Machine $m): array
    {
        [$code, $stdout, $stderr] = $result;
        foreach ($redirects as [$op, $target]) {
            $append = str_contains($op, '>>');
            $fd = ctype_digit($op[0]) ? (int) $op[0] : 1;
            if (str_ends_with($op, '>&')) {
                // 2>&1 : stderr rejoint stdout ; 1>&2 : l'inverse.
                if ($fd === 2 && $target === '1') {
                    $stdout .= $stderr;
                    $stderr = '';
                } elseif ($fd === 1 && $target === '2') {
                    $stderr .= $stdout;
                    $stdout = '';
                }
                continue;
            }
            $both = $op === '&>';
            $content = $both ? $stdout.$stderr : ($fd === 2 ? $stderr : $stdout);
            if ($both) {
                $stdout = $stderr = '';
            } elseif ($fd === 2) {
                $stderr = '';
            } else {
                $stdout = '';
            }
            if (\in_array($target, ['/dev/null', '/dev/stdout', '/dev/stderr', '/proc/self/fd/1', '/proc/self/fd/2'], true)) {
                if ($target !== '/dev/null') {
                    $stdout .= $content;
                }
                continue;
            }
            $path = $m->path($target);
            if (!$m->fs->isDir(\dirname($path))) {
                return [2, $stdout, $stderr.$this->shellPrefix($m).sprintf("cannot create %s: Directory nonexistent\n", $target)];
            }
            if (!$this->canWrite($m, $path)) {
                return [2, $stdout, $stderr.$this->shellPrefix($m).sprintf("cannot create %s: %s\n", $target, $this->writeDenied($m, $path))];
            }
            $previous = $append ? (string) $m->fs->read($path) : '';
            $m->fs->write($path, $previous.$content, null, $m->fs->exists($path) ? null : $m->userName());
        }

        return [$code, $stdout, $stderr];
    }

    /** Raison du refus d'écriture, telle que l'affichent les outils : montage :ro, ou droits. */
    public function writeDenied(Machine $m, string $path): string
    {
        return $m->fs->isReadOnly($path) ? 'Read-only file system' : 'Permission denied';
    }

    /** Un utilisateur non root n'écrit que dans ce qui lui appartient (ou /tmp) ; un montage :ro se refuse à tous. */
    public function canWrite(Machine $m, string $path): bool
    {
        if ($m->fs->isReadOnly($path)) {
            return false;
        }
        if ($m->isRoot() || Path::isUnder($path, '/tmp') || Path::isUnder($path, '/dev')) {
            return true;
        }
        $target = $m->fs->exists($path) ? $path : \dirname($path);
        if ($m->owns($m->fs->owner($target))) {
            return true;
        }

        return ($m->fs->mode($target) & 0002) !== 0;
    }

    // --- Expansion ------------------------------------------------------------------------

    /**
     * Mot => champs : variables et substitutions développées, découpage des parties non protégées,
     * motifs (*, ?) confrontés aux fichiers.
     *
     * @return list<string>
     */
    public function expandWord(array $word, Machine $m): array
    {
        $fields = [''];
        $hasQuoted = false;
        $globbable = false;
        foreach ($word as [$kind, $text]) {
            if ($kind === 'sq') {
                $fields[\count($fields) - 1] .= $text;
                $hasQuoted = true;
                continue;
            }
            if ($kind === 'dq') {
                if ($text === '$@') {
                    // "$@" : un champ par paramètre.
                    $params = $m->positional;
                    if ($params === []) {
                        $hasQuoted = $hasQuoted || \count($word) > 1;
                        continue;
                    }
                    $fields[\count($fields) - 1] .= array_shift($params);
                    foreach ($params as $param) {
                        $fields[] = $param;
                    }
                    $hasQuoted = true;
                    continue;
                }
                $fields[\count($fields) - 1] .= $this->expandString($text, $m, true);
                $hasQuoted = true;
                continue;
            }
            $expanded = $this->expandString($text, $m, false);
            if (Path::hasGlob($text)) {
                $globbable = true;
            }
            if ($expanded !== $text && preg_match('/\$/', $text)) {
                $parts = preg_split('/[ \t\n]+/', $expanded);
                if ($parts === false) {
                    $parts = [$expanded];
                }
                $fields[\count($fields) - 1] .= array_shift($parts);
                foreach ($parts as $part) {
                    $fields[] = $part;
                }
            } else {
                $fields[\count($fields) - 1] .= $expanded;
            }
        }
        $fields = array_values(array_filter($fields, static fn ($f) => $f !== '' || $hasQuoted));
        if ($globbable) {
            $globbed = [];
            foreach ($fields as $field) {
                array_push($globbed, ...$this->glob($field, $m));
            }

            return $globbed;
        }

        return $fields;
    }

    /** Mot => une seule chaîne (affectations, cibles de redirection). */
    public function expandText(array $word, Machine $m): string
    {
        $text = '';
        foreach ($word as [$kind, $segment]) {
            $text .= $kind === 'sq' ? $segment : $this->expandString($segment, $m, $kind === 'dq');
        }

        return $text;
    }

    public function expandString(string $text, Machine $m, bool $quoted): string
    {
        if ($quoted) {
            $text = preg_replace_callback('/\\\\([\\\\"$`])/', static fn ($x) => "\x00".$x[1], $text) ?? $text;
        }
        $result = '';
        $length = \strlen($text);
        for ($i = 0; $i < $length; ++$i) {
            $c = $text[$i];
            if ($c === "\x00") {
                $result .= $text[++$i] ?? '';
                continue;
            }
            if ($c !== '$' || $i + 1 >= $length) {
                $result .= $c;
                continue;
            }
            $next = $text[$i + 1];
            if ($next === '(') {
                $depth = 0;
                for ($j = $i + 1; $j < $length; ++$j) {
                    if ($text[$j] === '(') {
                        ++$depth;
                    } elseif ($text[$j] === ')' && --$depth === 0) {
                        break;
                    }
                }
                $inner = substr($text, $i + 2, $j - $i - 2);
                if (str_starts_with($inner, '(')) {
                    $result .= (string) $this->arithmetic(substr($inner, 1, -1), $m);
                } else {
                    $result .= rtrim($this->capture($inner, $m), "\n");
                }
                $i = $j;
                continue;
            }
            if ($next === '{') {
                $end = strpos($text, '}', $i);
                if ($end === false) {
                    $result .= $c;
                    continue;
                }
                $result .= $this->parameter(substr($text, $i + 2, $end - $i - 2), $m);
                $i = $end;
                continue;
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*/', substr($text, $i + 1), $match)) {
                $result .= $this->variable($match[0], $m);
                $i += \strlen($match[0]);
                continue;
            }
            if (\in_array($next, ['?', '@', '*', '#', '$', '!', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], true)) {
                $result .= $this->variable($next, $m);
                ++$i;
                continue;
            }
            $result .= $c;
        }

        return $result;
    }

    private function variable(string $name, Machine $m): string
    {
        return match (true) {
            $name === '@', $name === '*' => implode(' ', $m->positional),
            $name === '#' => (string) \count($m->positional),
            $name === '$' => '1',
            $name === '!' => '',
            $name === '0' => 'sh',
            ctype_digit($name) => $m->positional[(int) $name - 1] ?? '',
            $name === 'PWD' => $m->env['PWD'] ?? $m->cwd,
            $name === 'HOME' => $m->env['HOME'] ?? ($m->isRoot() ? '/root' : '/home/'.$m->userName()),
            $name === 'USER' => $m->env['USER'] ?? '',
            default => $m->env[$name] ?? '',
        };
    }

    private function parameter(string $expression, Machine $m): string
    {
        if (str_starts_with($expression, '#')) {
            return (string) \strlen($this->variable(substr($expression, 1), $m));
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*|[0-9@*#?])(?:(:?[-=+?])(.*))?$/s', $expression, $match)) {
            return '';
        }
        $name = $match[1];
        $set = \array_key_exists($name, $m->env) || (ctype_digit($name) && isset($m->positional[(int) $name - 1]));
        $value = $this->variable($name, $m);
        $op = $match[2] ?? '';
        $word = isset($match[3]) ? $this->expandString($match[3], $m, true) : '';
        $empty = str_starts_with($op, ':') ? $value === '' : !$set;

        return match (ltrim($op, ':')) {
            '-' => $empty ? $word : $value,
            '=' => $empty ? ($m->env[$name] = $word) : $value,
            '+' => $empty ? '' : $word,
            '?' => $empty ? throw new ExitSignal(1, $this->shellPrefix($m).$name.': '.($word !== '' ? $word : 'parameter not set')."\n") : $value,
            default => $value,
        };
    }

    private function arithmetic(string $expression, Machine $m): int
    {
        $expression = preg_replace_callback('/\$?\b([A-Za-z_][A-Za-z0-9_]*)\b/', fn ($x) => (string) (int) ($m->env[$x[1]] ?? 0), $expression) ?? '0';
        if (!preg_match('#^[\d\s+\-*/%()]+$#', $expression)) {
            return 0;
        }
        try {
            return (int) eval('return '.$expression.';');
        } catch (\Throwable) {
            return 0;
        }
    }

    /** $(commande) : sa sortie standard. */
    private function capture(string $script, Machine $m): string
    {
        $saved = $m->output;
        $m->output = '';
        $env = $m->env;
        try {
            $ast = $this->parser->parse($script);
            $out = '';
            foreach ($ast as $andor) {
                foreach ($andor['parts'] as [, $pipeline]) {
                    $input = '';
                    foreach ($pipeline['commands'] as $command) {
                        [, $stdout, $stderr] = $this->execCommand($command, $m, $input);
                        $input = $stdout;
                        $saved .= $stderr;
                    }
                    $out .= $input;
                }
            }

            return $out;
        } catch (SyntaxError|ExitSignal) {
            return '';
        } finally {
            $m->output = $saved;
            $m->env = $env + $m->env;
        }
    }

    /** @return list<string> */
    private function glob(string $pattern, Machine $m): array
    {
        if (!Path::hasGlob($pattern)) {
            return [$pattern];
        }
        $absolute = $m->path($pattern);
        $segments = explode('/', ltrim($absolute, '/'));
        $candidates = ['/'];
        foreach ($segments as $segment) {
            $next = [];
            foreach ($candidates as $base) {
                $dir = rtrim($base, '/');
                if (!Path::hasGlob($segment)) {
                    $path = $dir.'/'.$segment;
                    if ($m->fs->exists($path)) {
                        $next[] = $path;
                    }
                    continue;
                }
                $regex = Path::globRegex($segment);
                foreach ($m->fs->list($dir === '' ? '/' : $dir) as $name) {
                    if (($name[0] !== '.' || $segment[0] === '.') && preg_match($regex, $name)) {
                        $next[] = $dir.'/'.$name;
                    }
                }
            }
            $candidates = $next;
        }
        if ($candidates === []) {
            return [$pattern]; // pas de correspondance : le motif reste tel quel (comportement POSIX)
        }
        sort($candidates);
        if ($pattern[0] !== '/') {
            $prefix = rtrim($m->cwd, '/').'/';

            return array_map(static fn ($c) => str_starts_with($c, $prefix) ? substr($c, \strlen($prefix)) : $c, $candidates);
        }

        return $candidates;
    }

    // --- Commandes internes ---------------------------------------------------------------

    /** @param list<string> $args */
    private function builtin(string $name, array $args, Machine $m, string $stdin): Result
    {
        switch ($name) {
            case 'echo':
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
            case 'printf':
                $format = array_shift($args) ?? '';
                $format = stripcslashes($format);
                try {
                    $out = $args === [] ? $format : vsprintf(preg_replace('/%([^sdifxo%])/', '%%$1', $format) ?? $format, array_pad($args, substr_count($format, '%'), ''));
                } catch (\ValueError) {
                    $out = $format;
                }

                return Result::ok($out);
            case 'cd':
                $target = $m->path($args[0] ?? ($m->env['HOME'] ?? '/root'));
                if (!$m->fs->isDir($target)) {
                    return Result::error(2, $this->shellPrefix($m).'cd: can\'t cd to '.($args[0] ?? '~').": No such file or directory\n");
                }
                $m->cwd = $target;
                $m->env['PWD'] = $target;

                return Result::ok();
            case 'pwd':
                return Result::ok($m->cwd."\n");
            case 'set':
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
            case 'export':
            case 'local':
                foreach ($args as $arg) {
                    if (str_contains($arg, '=')) {
                        [$var, $value] = explode('=', $arg, 2);
                        $m->env[$var] = $value;
                    } elseif ($arg !== '-p') {
                        $m->env[$arg] ??= '';
                    }
                }

                return Result::ok();
            case 'unset':
                foreach ($args as $arg) {
                    unset($m->env[$arg]);
                }

                return Result::ok();
            case 'exit':
            case 'return':
                throw new ExitSignal((int) ($args[0] ?? ($m->env['?'] ?? 0)), '');
            case 'true':
            case ':':
            case 'wait':
            case 'trap':
            case 'umask':
            case 'ulimit':
            case 'alias':
            case 'hash':
                return Result::ok();
            case 'false':
                return new Result(1);
            case 'shift':
                array_splice($m->positional, 0, (int) ($args[0] ?? 1));

                return Result::ok();
            case 'read':
                $line = strtok($stdin, "\n") ?: '';
                foreach (array_filter($args, static fn ($a) => $a[0] !== '-') as $i => $var) {
                    $m->env[$var] = $line;
                }

                return new Result($stdin === '' ? 1 : 0);
            case 'test':
            case '[':
                if ($name === '[') {
                    if (end($args) !== ']') {
                        return Result::error(2, $this->shellPrefix($m)."[: missing ]\n");
                    }
                    array_pop($args);
                }

                return new Result($this->test($args, $m) ? 0 : 1);
            case 'command':
            case 'type':
                $query = array_values(array_filter($args, static fn ($a) => $a[0] !== '-'));
                if ($query === []) {
                    return Result::ok();
                }
                if (\in_array('-v', $args, true) || $name === 'type') {
                    $found = \in_array($query[0], self::BUILTINS, true) || $m->facts->hasBinary($query[0]);

                    return $found ? Result::ok(($name === 'type' ? $query[0].' is ' : '').(\in_array($query[0], self::BUILTINS, true) ? $query[0] : '/usr/bin/'.$query[0])."\n") : new Result(1);
                }

                return $this->invoke($query, $m, $stdin);
            case 'exec':
                if ($args === []) {
                    return Result::ok();
                }
                // Dans un script d'entrée, « exec "$@" » remplace le shell par la commande.
                if ($m->mode === Machine::EXEC && $this->depth <= 2) {
                    $m->execTarget = $args;

                    throw new ExitSignal(0, '');
                }

                return $this->invoke($args, $m, $stdin);
            case 'eval':
                $out = $this->runScript(implode(' ', $args), $m, $m->positional, $stdin);

                return $out;
            case '.':
            case 'source':
                $content = $m->fs->read($m->path($args[0] ?? ''));
                if ($content === null) {
                    return Result::error(2, $this->shellPrefix($m).'.: '.($args[0] ?? '').": not found\n");
                }
                $this->run($content, $m, $stdin);

                return Result::ok();
        }

        return Result::ok();
    }

    /** @param list<string> $args */
    public function test(array $args, Machine $m): bool
    {
        if ($args === []) {
            return false;
        }
        if ($args[0] === '!') {
            return !$this->test(\array_slice($args, 1), $m);
        }
        foreach (['-o', '-a'] as $logical) {
            $position = array_search($logical, $args, true);
            if ($position !== false && $position > 0) {
                $left = $this->test(\array_slice($args, 0, $position), $m);
                $right = $this->test(\array_slice($args, $position + 1), $m);

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
                '-w' => $m->fs->exists($path) && $this->canWrite($m, $path),
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
