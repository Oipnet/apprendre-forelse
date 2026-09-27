<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell\Command;

use Forelse\DockerSim\Shell\Interpreter;
use Forelse\DockerSim\Shell\Machine;
use Forelse\DockerSim\Shell\Result;

/** Les utilitaires de texte (coreutils / busybox) : sed, grep, awk, tri, découpe, comparaison, empreintes. */
final class TextCommands extends CoreutilsCommands
{
    public function names(): array
    {
        return ['sed', 'grep', 'egrep', 'fgrep', 'head', 'tail', 'wc', 'sort', 'uniq', 'tee', 'cut', 'tr', 'awk', 'seq', 'diff', 'md5sum', 'sha256sum'];
    }

    /** @param list<string> $args */
    protected function dispatch(string $name, array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        return match ($name) {
            'sed' => $this->sed($args, $m, $stdin),
            'grep', 'egrep', 'fgrep' => $this->grep($args, $m, $stdin, $name),
            'head', 'tail' => $this->headTail($name, $args, $m, $stdin),
            'wc' => $this->wc($args, $m, $stdin),
            'sort' => Result::ok($this->lines($this->input($args, $m, $stdin), static function (array $l) { sort($l); return $l; })),
            'uniq' => Result::ok($this->lines($this->input($args, $m, $stdin), static fn (array $l) => array_values(array_unique($l)))),
            'tee' => $this->tee($args, $m, $stdin, $sh),
            'cut' => $this->cut($args, $m, $stdin),
            'tr' => Result::ok(\count($args) >= 2 && $args[0] !== '-d' ? strtr($stdin, $this->trSet($args[0]), $this->trSet($args[1])) : str_replace(str_split($this->trSet($args[1] ?? '')), '', $stdin)),
            'awk' => $this->awk($args, $m, $stdin),
            'seq' => Result::ok(implode("\n", range((int) (\count($args) > 1 ? $args[0] : 1), (int) end($args)))."\n"),
            'diff' => $this->diff($args, $m),
            'md5sum', 'sha256sum' => $this->checksum($name, $args, $m, $stdin),
            default => throw new \LogicException(sprintf('%s : « %s » est déclarée sans implémentation.', self::class, $name)),
        };
    }

    /** @param list<string> $args */
    private function input(array $args, Machine $m, string $stdin): string
    {
        $files = array_values(array_filter($args, static fn ($a) => $a === '-' || $a[0] !== '-'));
        if ($files === []) {
            return $stdin;
        }
        $content = '';
        foreach ($files as $file) {
            $content .= $file === '-' ? $stdin : (string) $m->fs->read($m->path($file));
        }

        return $content;
    }

    private function lines(string $text, callable $transform): string
    {
        $lines = explode("\n", rtrim($text, "\n"));
        $lines = $transform($lines);

        return $lines === [''] || $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    /** @param list<string> $args */
    private function sed(array $args, Machine $m, string $stdin): Result
    {
        $inPlace = false;
        $extended = false;
        $quiet = false;
        $scripts = [];
        $files = [];
        for ($i = 0; $i < \count($args); ++$i) {
            $arg = $args[$i];
            if ($arg === '-e' || $arg === '--expression') {
                $scripts[] = $args[++$i] ?? '';
            } elseif (preg_match('/^-[a-zA-Z]+$/', $arg)) {
                $inPlace = $inPlace || str_contains($arg, 'i');
                $extended = $extended || str_contains($arg, 'r') || str_contains($arg, 'E');
                $quiet = $quiet || str_contains($arg, 'n');
                if (str_ends_with($arg, 'e')) {
                    $scripts[] = $args[++$i] ?? '';
                }
            } elseif (str_starts_with($arg, '-i')) {
                $inPlace = true;
            } elseif ($scripts === [] ) {
                $scripts[] = $arg;
            } else {
                $files[] = $arg;
            }
        }
        $apply = function (string $text) use ($scripts, $extended, $m): string {
            foreach ($scripts as $script) {
                foreach (preg_split('/;\s*(?=[sd\/0-9$])/', $script) ?: [] as $command) {
                    $text = $this->sedCommand(trim($command), $text, $extended, $m);
                }
            }

            return $text;
        };
        if ($files === []) {
            return Result::ok($apply($stdin));
        }
        $out = '';
        $err = '';
        foreach ($files as $file) {
            $path = $m->path($file);
            if (!$m->fs->isFile($path)) {
                $err .= "sed: {$file}: No such file or directory\n";
                continue;
            }
            $result = $apply((string) $m->fs->read($path));
            if ($inPlace) {
                $m->fs->write($path, $result);
            } else {
                $out .= $result;
            }
        }

        return new Result($err === '' ? 0 : ($m->facts->os === 'alpine' ? 1 : 2), $quiet ? '' : $out, $err);
    }

    private function sedCommand(string $command, string $text, bool $extended, Machine $m): string
    {
        if ($command === '') {
            return $text;
        }
        if ($command[0] === 's' && \strlen($command) > 1) {
            $delimiter = $command[1];
            $parts = $this->splitUnescaped(substr($command, 2), $delimiter);
            if (\count($parts) < 2) {
                return $text;
            }
            [$pattern, $replacement] = $parts;
            $flags = $parts[2] ?? '';
            $regex = $this->sedRegex($pattern, $extended);
            $replacement = preg_replace('/\\\\(\d)/', '\${$1}', str_replace(['$', '&'], ['\$', '${0}'], $replacement)) ?? $replacement;
            $replacement = str_replace('\\'.$delimiter, $delimiter, $replacement);
            $lines = explode("\n", $text);
            foreach ($lines as &$line) {
                $line = (string) @preg_replace('#'.str_replace('#', '\#', $regex).'#'.(str_contains($flags, 'I') ? 'i' : ''), $replacement, $line, str_contains($flags, 'g') ? -1 : 1);
            }

            return implode("\n", $lines);
        }
        if (preg_match('#^/(.*)/d$#', $command, $match)) {
            $regex = $this->sedRegex($match[1], $extended);
            $lines = array_filter(explode("\n", $text), static fn ($l) => !@preg_match('#'.str_replace('#', '\#', $regex).'#', $l));

            return implode("\n", $lines);
        }
        if (preg_match('#^/(.*)/a\\\\?\s*(.*)$#', $command, $match)) {
            $regex = $this->sedRegex($match[1], $extended);
            $out = [];
            foreach (explode("\n", $text) as $line) {
                $out[] = $line;
                if (@preg_match('#'.str_replace('#', '\#', $regex).'#', $line)) {
                    $out[] = $match[2];
                }
            }

            return implode("\n", $out);
        }
        if (preg_match('#^\$a\\\\?\s*(.*)$#', $command, $match)) {
            return rtrim($text, "\n")."\n".$match[1]."\n";
        }

        return $text;
    }

    /** @return list<string> */
    private function splitUnescaped(string $text, string $delimiter): array
    {
        $parts = [''];
        $length = \strlen($text);
        for ($i = 0; $i < $length; ++$i) {
            if ($text[$i] === '\\' && $i + 1 < $length) {
                $parts[\count($parts) - 1] .= $text[$i].$text[$i + 1];
                ++$i;
                continue;
            }
            if ($text[$i] === $delimiter) {
                $parts[] = '';
                continue;
            }
            $parts[\count($parts) - 1] .= $text[$i];
        }

        return $parts;
    }

    private function sedRegex(string $pattern, bool $extended): string
    {
        if (!$extended) {
            // BRE : \( \) \{ \} \+ \? sont les opérateurs ; ( ) { } + ? des caractères.
            $pattern = strtr($pattern, ['\\(' => "\x01", '\\)' => "\x02", '\\{' => "\x03", '\\}' => "\x04", '\\+' => "\x05", '\\?' => "\x06", '(' => '\\(', ')' => '\\)', '{' => '\\{', '}' => '\\}', '+' => '\\+', '?' => '\\?']);
            $pattern = strtr($pattern, ["\x01" => '(', "\x02" => ')', "\x03" => '{', "\x04" => '}', "\x05" => '+', "\x06" => '?']);
        }

        return $pattern;
    }

    /** @param list<string> $args */
    private function grep(array $args, Machine $m, string $stdin, string $name = 'grep'): Result
    {
        $flags = $name === 'egrep' ? 'E' : ($name === 'fgrep' ? 'F' : '');
        $patterns = [];
        $files = [];
        $maxCount = null;
        $noMoreOptions = false;
        for ($i = 0; $i < \count($args); ++$i) {
            $arg = $args[$i];
            if ($noMoreOptions || $arg === '-' || $arg === '' || $arg[0] !== '-') {
                if ($patterns === [] && !str_contains($flags, 'e')) {
                    $patterns[] = $arg;
                } else {
                    $files[] = $arg;
                }
                continue;
            }
            if ($arg === '--') {
                $noMoreOptions = true;
            } elseif ($arg === '-e' || $arg === '--regexp') {
                $patterns[] = $args[++$i] ?? '';
                $flags .= 'e';
            } elseif (str_starts_with($arg, '--regexp=')) {
                $patterns[] = substr($arg, 9);
                $flags .= 'e';
            } elseif (preg_match('/^-m(\d*)$/', $arg, $mm) || preg_match('/^--max-count=(\d+)$/', $arg, $mm)) {
                $maxCount = (int) ($mm[1] !== '' ? $mm[1] : ($args[++$i] ?? 0));
            } elseif (preg_match('/^-[ABC](\d*)$/', $arg, $mm)) {
                if ($mm[1] === '') {
                    ++$i; // -A 3 : le contexte n'est pas affiché par le simulateur.
                }
            } elseif (str_starts_with($arg, '--')) {
                $long = ['--extended-regexp' => 'E', '--fixed-strings' => 'F', '--perl-regexp' => 'P', '--ignore-case' => 'i', '--invert-match' => 'v', '--word-regexp' => 'w', '--line-regexp' => 'x', '--count' => 'c', '--quiet' => 'q', '--silent' => 'q', '--no-messages' => 's', '--line-number' => 'n', '--with-filename' => 'H', '--no-filename' => 'h', '--files-with-matches' => 'l', '--files-without-match' => 'L', '--only-matching' => 'o', '--recursive' => 'r', '--dereference-recursive' => 'r'];
                $flags .= $long[explode('=', $arg)[0]] ?? '';
            } else {
                $flags .= substr($arg, 1);
            }
        }
        if ($patterns === []) {
            return Result::error(2, "Usage: grep [OPTION]... PATTERNS [FILE]...\nTry 'grep --help' for more information.\n");
        }
        $has = static fn (string $f) => str_contains($flags, $f);
        $regexes = [];
        foreach (explode("\n", implode("\n", $patterns)) as $pattern) {
            $body = match (true) {
                $has('F') => preg_quote($pattern, '#'),
                $has('E') || $has('P') => str_replace('#', '\#', $pattern),
                default => self::basicRegex($pattern),
            };
            if ($has('w')) {
                $body = '(?<![\w])(?:'.$body.')(?![\w])';
            }
            if ($has('x')) {
                $body = '^(?:'.$body.')$';
            }
            $regexes[] = '#'.$body.'#'.($has('i') ? 'i' : '').'u';
        }
        $recursive = $has('r') || $has('R');
        if ($files === [] && $recursive) {
            $files = ['.'];
        }
        $sources = $files === [] ? ['(standard input)' => $stdin] : [];
        $err = '';
        foreach ($files as $file) {
            $path = $m->path($file);
            if ($m->fs->isDir($path)) {
                if (!$recursive) {
                    $err .= "grep: {$file}: Is a directory\n";
                    continue;
                }
                foreach ($m->fs->files($path) as $child) {
                    $shown = $file === '.' ? substr($child, \strlen(rtrim($path, '/')) + 1) : rtrim($file, '/').substr($child, \strlen(rtrim($path, '/')));
                    $sources[$shown] = (string) $m->fs->read($child);
                }
                continue;
            }
            if (!$m->fs->isFile($path)) {
                if (!$has('s')) {
                    $err .= "grep: {$file}: No such file or directory\n";
                }
                continue;
            }
            $sources[$file] = (string) $m->fs->read($path);
        }
        $prefixName = $has('H') || (!$has('h') && (\count($files) > 1 || $recursive));
        $out = '';
        $selected = false;
        foreach ($sources as $source => $content) {
            $count = 0;
            $lines = $content === '' ? [] : explode("\n", str_ends_with($content, "\n") ? substr($content, 0, -1) : $content);
            foreach ($lines as $number => $line) {
                if ($maxCount !== null && $count >= $maxCount) {
                    break;
                }
                $found = [];
                $hit = false;
                foreach ($regexes as $regex) {
                    if (@preg_match_all($regex, $line, $all) > 0) {
                        $hit = true;
                        array_push($found, ...array_filter($all[0], static fn ($x) => $x !== ''));
                    }
                }
                if ($hit === $has('v')) {
                    continue;
                }
                ++$count;
                $selected = true;
                if ($has('q') || $has('l') || $has('L') || $has('c')) {
                    continue;
                }
                $prefix = ($prefixName ? $source.':' : '').($has('n') ? ($number + 1).':' : '');
                if ($has('o') && !$has('v')) {
                    foreach ($found as $piece) {
                        $out .= $prefix.$piece."\n";
                    }
                } else {
                    $out .= $prefix.$line."\n";
                }
            }
            if ($has('c')) {
                $out .= ($prefixName ? $source.':' : '').$count."\n";
            } elseif ($has('l') && $count > 0) {
                $out .= $source."\n";
            } elseif ($has('L') && $count === 0) {
                $out .= $source."\n";
            }
        }
        if ($has('q')) {
            return new Result($selected ? 0 : ($err !== '' ? 2 : 1), '', $has('s') ? '' : $err);
        }
        $code = $err !== '' ? 2 : ($selected ? 0 : 1);

        return new Result($code, $out, $err);
    }

    /** Une expression régulière « basique » (BRE, le défaut de grep et de sed) traduite en PCRE : \| \( \) \{ \} \+ \? sont les opérateurs, | ( ) { } + ? des caractères. */
    public static function basicRegex(string $pattern): string
    {
        $out = '';
        $length = \strlen($pattern);
        $inClass = false;
        for ($i = 0; $i < $length; ++$i) {
            $c = $pattern[$i];
            if ($inClass) {
                $out .= $c === '#' ? '\#' : $c;
                if ($c === ']') {
                    $inClass = false;
                }
                continue;
            }
            if ($c === '[') {
                $inClass = true;
                $out .= $c;
                if (($pattern[$i + 1] ?? '') === '^') {
                    $out .= '^';
                    ++$i;
                }
                if (($pattern[$i + 1] ?? '') === ']') {
                    $out .= '\]';
                    ++$i;
                }
                continue;
            }
            if ($c === '\\' && $i + 1 < $length) {
                $next = $pattern[++$i];
                $out .= \in_array($next, ['|', '(', ')', '{', '}', '+', '?'], true) ? $next : '\\'.$next;
                continue;
            }
            $out .= match ($c) {
                '|', '(', ')', '{', '}', '+', '?', '#' => '\\'.$c,
                default => $c,
            };
        }

        return $out;
    }

    /** @param list<string> $args */
    private function headTail(string $name, array $args, Machine $m, string $stdin): Result
    {
        $count = 10;
        $files = [];
        for ($i = 0; $i < \count($args); ++$i) {
            if ($args[$i] === '-n') {
                $count = (int) ltrim($args[++$i] ?? '10', '+');
            } elseif (preg_match('/^-(\d+)$/', $args[$i], $match)) {
                $count = (int) $match[1];
            } elseif ($args[$i] === '-f' || $args[$i] === '-F') {
                continue;
            } elseif ($args[$i][0] !== '-') {
                $files[] = $args[$i];
            }
        }
        $content = $files === [] ? $stdin : (string) $m->fs->read($m->path($files[0]));
        $lines = explode("\n", rtrim($content, "\n"));
        $lines = $name === 'head' ? \array_slice($lines, 0, $count) : \array_slice($lines, -$count);

        return Result::ok($content === '' ? '' : implode("\n", $lines)."\n");
    }

    /** @param list<string> $args */
    private function wc(array $args, Machine $m, string $stdin): Result
    {
        $content = $this->input($args, $m, $stdin);
        $lines = substr_count($content, "\n");
        if (\in_array('-l', $args, true)) {
            return Result::ok($lines."\n");
        }
        if (\in_array('-c', $args, true)) {
            return Result::ok(\strlen($content)."\n");
        }

        return Result::ok(sprintf("%7d %7d %7d\n", $lines, str_word_count($content), \strlen($content)));
    }

    /** @param list<string> $args */
    private function tee(array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        $append = \in_array('-a', $args, true);
        foreach (array_filter($args, static fn ($a) => $a[0] !== '-') as $file) {
            $path = $m->path($file);
            if (!$sh->canWrite($m, $path)) {
                return Result::error(1, "tee: {$file}: ".$sh->writeDenied($m, $path)."\n", $stdin);
            }
            $m->fs->write($path, ($append ? (string) $m->fs->read($path) : '').$stdin);
        }

        return Result::ok($stdin);
    }

    /** @param list<string> $args */
    private function cut(array $args, Machine $m, string $stdin): Result
    {
        $delimiter = "\t";
        $fields = '1';
        $files = [];
        for ($i = 0; $i < \count($args); ++$i) {
            if (str_starts_with($args[$i], '-d')) {
                $delimiter = \strlen($args[$i]) > 2 ? substr($args[$i], 2) : ($args[++$i] ?? "\t");
            } elseif (str_starts_with($args[$i], '-f')) {
                $fields = \strlen($args[$i]) > 2 ? substr($args[$i], 2) : ($args[++$i] ?? '1');
            } else {
                $files[] = $args[$i];
            }
        }
        $wanted = array_map('intval', explode(',', $fields));
        $content = $files === [] ? $stdin : (string) $m->fs->read($m->path($files[0]));

        return Result::ok($this->lines($content, static function (array $lines) use ($delimiter, $wanted) {
            return array_map(static function ($line) use ($delimiter, $wanted) {
                $parts = explode($delimiter, $line);

                return implode($delimiter, array_map(static fn ($w) => $parts[$w - 1] ?? '', $wanted));
            }, $lines);
        }));
    }

    private function trSet(string $set): string
    {
        return strtr($set, ['[:lower:]' => 'abcdefghijklmnopqrstuvwxyz', '[:upper:]' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'a-z' => 'abcdefghijklmnopqrstuvwxyz', 'A-Z' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', '\\n' => "\n", '\\r' => "\r"]);
    }

    /** @param list<string> $args */
    private function awk(array $args, Machine $m, string $stdin): Result
    {
        $separator = null;
        $program = '';
        $files = [];
        for ($i = 0; $i < \count($args); ++$i) {
            if (str_starts_with($args[$i], '-F')) {
                $separator = \strlen($args[$i]) > 2 ? substr($args[$i], 2) : ($args[++$i] ?? ' ');
            } elseif ($program === '') {
                $program = $args[$i];
            } else {
                $files[] = $args[$i];
            }
        }
        $content = $files === [] ? $stdin : (string) $m->fs->read($m->path($files[0]));
        if (!preg_match('/\{\s*print\s+([^}]*)\}/', $program, $match)) {
            return Result::ok($content);
        }
        $fields = array_map('trim', explode(',', $match[1]));

        return Result::ok($this->lines($content, static fn (array $lines) => array_map(static function ($line) use ($separator, $fields) {
            $parts = $separator === null ? (preg_split('/\s+/', trim($line)) ?: []) : explode($separator, $line);

            return implode(' ', array_map(static fn ($f) => $f === '$0' ? $line : ($parts[(int) ltrim($f, '$') - 1] ?? trim($f, '"')), $fields));
        }, $lines)));
    }

    /** @param list<string> $args */
    private function diff(array $args, Machine $m): Result
    {
        $files = array_values(array_filter($args, static fn ($a) => $a[0] !== '-'));
        if (\count($files) < 2) {
            return Result::error(2, "diff: missing operand\n");
        }
        $a = $m->fs->read($m->path($files[0]));
        $b = $m->fs->read($m->path($files[1]));

        return $a === $b ? Result::ok() : new Result(1, "--- {$files[0]}\n+++ {$files[1]}\n");
    }

    /** @param list<string> $args */
    private function checksum(string $name, array $args, Machine $m, string $stdin): Result
    {
        if (\in_array('-c', $args, true)) {
            return Result::ok("OK\n");
        }
        $files = array_values(array_filter($args, static fn ($a) => $a[0] !== '-'));
        $algo = $name === 'md5sum' ? 'md5' : 'sha256';
        if ($files === []) {
            return Result::ok(hash($algo, $stdin)."  -\n");
        }
        $out = '';
        foreach ($files as $file) {
            $out .= hash($algo, (string) $m->fs->read($m->path($file))).'  '.$file."\n";
        }

        return Result::ok($out);
    }
}
