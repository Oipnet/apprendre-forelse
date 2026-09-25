<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell\Command;

use Forelse\DockerSim\Fs\Path;
use Forelse\DockerSim\Shell\Interpreter;
use Forelse\DockerSim\Shell\Machine;
use Forelse\DockerSim\Shell\Result;

/** Les utilitaires de base (coreutils / busybox) : fichiers, texte, processus. */
final class CoreCommands implements Command
{
    public function names(): array
    {
        return ['cat', 'ls', 'mkdir', 'rm', 'rmdir', 'cp', 'mv', 'ln', 'touch', 'chmod', 'chown', 'chgrp', 'env', 'printenv', 'id', 'whoami', 'sleep', 'sed', 'grep', 'egrep', 'fgrep', 'head', 'tail', 'wc', 'sort', 'uniq', 'tee', 'which', 'basename', 'dirname', 'nproc', 'date', 'uname', 'hostname', 'find', 'tar', 'gzip', 'gunzip', 'cut', 'tr', 'xargs', 'sh', 'bash', 'ash', 'dash', 'du', 'df', 'ps', 'kill', 'diff', 'md5sum', 'sha256sum', 'readlink', 'realpath', 'awk', 'stat', 'seq', 'xz', 'bzip2', 'unzip', 'zip', 'less', 'more', 'file', 'nano', 'vi', 'vim', 'top', 'htop', 'tini', 'dumb-init', 'su-exec', 'gosu', 'su', 'sudo'];
    }

    public function run(string $name, array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        $result = $this->dispatch($name, $args, $m, $stdin, $sh);
        if ($m->facts->os === 'alpine' || $result->stderr === '') {
            return $result;
        }

        return new Result($result->code, $result->stdout, self::coreutils($result->stderr), $result->seconds);
    }

    /**
     * Les messages d'erreur sont écrits à la façon de BusyBox (Alpine). Debian a les coreutils de GNU,
     * qui ne disent pas les choses de la même manière : on traduit, pour qu'un apprenant qui compare
     * avec sa machine retrouve exactement ce que son terminal affiche.
     */
    public static function coreutils(string $stderr): string
    {
        return (string) preg_replace(
            [
                "/^mkdir: can't create directory '([^']*)': /m",
                "/^rm: can't remove '([^']*)': /m",
                "/^cp: can't stat '([^']*)': /m",
                "/^mv: can't stat '([^']*)': /m",
                "/^cp: can't create '([^']*)': /m",
                "/^touch: ([^:\n]+): (No such file or directory|Permission denied|Read-only file system)$/m",
                "/^ls: ([^:\n]+): No such file or directory$/m",
                "/^(chmod|chown|chgrp): ([^:\n]+): No such file or directory$/m",
                "/^sed: ([^:\n]+): No such file or directory$/m",
                "/^find: ([^:\n]+): No such file or directory$/m",
                "/^stat: can't stat '([^']*)': /m",
                "/^cp: omitting directory '([^']*)'$/m",
                "/^chown: ([^:\\n]+): Operation not permitted$/m",
                "/^chmod: ([^:\\n]+): Operation not permitted$/m",
            ],
            [
                "mkdir: cannot create directory '$1': ",
                "rm: cannot remove '$1': ",
                "cp: cannot stat '$1': ",
                "mv: cannot stat '$1': ",
                "cp: cannot create regular file '$1': ",
                "touch: cannot touch '$1': $2",
                "ls: cannot access '$1': No such file or directory",
                "$1: cannot access '$2': No such file or directory",
                "sed: can't read $1: No such file or directory",
                "find: '$1': No such file or directory",
                "stat: cannot statx '$1': ",
                "cp: -r not specified; omitting directory '$1'",
                "chown: changing ownership of '$1': Operation not permitted",
                "chmod: changing permissions of '$1': Operation not permitted",
            ],
            $stderr,
        );
    }

    private function dispatch(string $name, array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        $p = $sh->shellPrefix($m);

        return match ($name) {
            'cat' => $this->cat($args, $m, $stdin),
            'ls' => $this->ls($args, $m),
            'mkdir' => $this->mkdir($args, $m, $sh),
            'rm' => $this->rm($args, $m, $sh),
            'rmdir' => $this->rm(['-r', ...$args], $m, $sh),
            'cp' => $this->copy($args, $m, $sh, move: false),
            'mv' => $this->copy($args, $m, $sh, move: true),
            'ln' => $this->ln($args, $m),
            'touch' => $this->touch($args, $m, $sh),
            'chmod' => $this->chmod($args, $m),
            'chown', 'chgrp' => $this->chown($args, $m, $name === 'chgrp'),
            'env', 'printenv' => $this->env($name, $args, $m, $stdin, $sh),
            'id' => $this->id($args, $m),
            'whoami' => Result::ok($m->userName()."\n"),
            'sleep' => Result::ok('', min(5.0, (float) ($args[0] ?? 0))),
            'sed' => $this->sed($args, $m, $stdin),
            'grep', 'egrep', 'fgrep' => $this->grep($args, $m, $stdin, $name),
            'head', 'tail' => $this->headTail($name, $args, $m, $stdin),
            'wc' => $this->wc($args, $m, $stdin),
            'sort' => Result::ok($this->lines($this->input($args, $m, $stdin), static function (array $l) { sort($l); return $l; })),
            'uniq' => Result::ok($this->lines($this->input($args, $m, $stdin), static fn (array $l) => array_values(array_unique($l)))),
            'tee' => $this->tee($args, $m, $stdin, $sh),
            'which' => $this->which($args, $m),
            'basename' => Result::ok(basename($args[0] ?? '', $args[1] ?? '')."\n"),
            'dirname' => Result::ok(\dirname($args[0] ?? '.')."\n"),
            'nproc' => Result::ok("4\n"),
            'date' => Result::ok(gmdate(isset($args[0]) && str_starts_with($args[0], '+') ? $this->dateFormat(substr($args[0], 1)) : 'D M j H:i:s \U\T\C Y')."\n"),
            'uname' => Result::ok(\in_array('-a', $args, true) ? "Linux {$m->hostname} 6.10.14-linuxkit #1 SMP x86_64 GNU/Linux\n" : (\in_array('-m', $args, true) ? "x86_64\n" : "Linux\n")),
            'hostname' => Result::ok($m->hostname."\n"),
            'find' => $this->find($args, $m, $sh),
            'tar', 'gzip', 'gunzip', 'xz', 'bzip2', 'unzip', 'zip' => $this->archive($name, $args, $m),
            'cut' => $this->cut($args, $m, $stdin),
            'tr' => Result::ok(\count($args) >= 2 && $args[0] !== '-d' ? strtr($stdin, $this->trSet($args[0]), $this->trSet($args[1])) : str_replace(str_split($this->trSet($args[1] ?? '')), '', $stdin)),
            'xargs' => $this->xargs($args, $m, $stdin, $sh),
            'sh', 'bash', 'ash', 'dash' => $this->shell($args, $m, $stdin, $sh),
            'du' => $this->du($args, $m),
            'df' => Result::ok("Filesystem           1K-blocks      Used Available Use% Mounted on\noverlay               61202244  18034412  40026508  31% /\n"),
            'ps' => $this->ps($m),
            'kill' => $this->kill($args, $m),
            'diff' => $this->diff($args, $m),
            'md5sum', 'sha256sum' => $this->checksum($name, $args, $m, $stdin),
            'readlink', 'realpath' => Result::ok($m->path(end($args) ?: '.')."\n"),
            'awk' => $this->awk($args, $m, $stdin),
            'stat' => $this->stat($args, $m),
            'seq' => Result::ok(implode("\n", range((int) (\count($args) > 1 ? $args[0] : 1), (int) end($args)))."\n"),
            'less', 'more' => $this->cat($args, $m, $stdin),
            'file' => Result::ok(($args[0] ?? '').': '.($m->fs->isDir($m->path($args[0] ?? '')) ? 'directory' : 'ASCII text')."\n"),
            'nano', 'vi', 'vim', 'top', 'htop' => Result::error(1, sprintf("%s: pas de terminal interactif dans le simulateur (utilisez cat, sed ou echo)\n", $name)),
            'tini', 'dumb-init' => $this->wrapper($args, $m, $stdin, $sh),
            'su-exec', 'gosu' => $this->switchUser($name, $args, $m, $stdin, $sh),
            'su', 'sudo' => Result::error(1, $name === 'sudo' ? $p."sudo: not found\n" : "su: must be run from a terminal\n"),
            default => Result::ok(),
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

    private function cat(array $args, Machine $m, string $stdin): Result
    {
        $files = array_values(array_filter($args, static fn ($a) => $a === '-' || $a[0] !== '-'));
        if ($files === []) {
            return Result::ok($stdin);
        }
        $out = '';
        $err = '';
        $code = 0;
        foreach ($files as $file) {
            if ($file === '-') {
                $out .= $stdin;
                continue;
            }
            $path = $m->path($file);
            if ($m->fs->isDir($path)) {
                $err .= "cat: {$file}: Is a directory\n";
                $code = 1;
            } elseif (!$m->fs->isFile($path)) {
                $err .= "cat: can't open '{$file}': No such file or directory\n";
                $code = 1;
            } else {
                $out .= (string) $m->fs->read($path);
            }
        }

        return new Result($code, $out, $m->facts->os === 'alpine' ? $err : str_replace("can't open '", '', str_replace("': No", ': No', $err)));
    }

    private function ls(array $args, Machine $m): Result
    {
        $flags = implode('', array_map(static fn ($a) => ltrim($a, '-'), array_filter($args, static fn ($a) => $a !== '' && $a[0] === '-' && $a !== '-')));
        $operands = array_values(array_filter($args, static fn ($a) => $a === '' || $a[0] !== '-'));
        $targets = $operands ?: ['.'];
        $numeric = str_contains($flags, 'n');
        $long = str_contains($flags, 'l') || $numeric;
        $dots = str_contains($flags, 'a');
        $hidden = $dots || str_contains($flags, 'A');
        $human = str_contains($flags, 'h');
        $recursive = str_contains($flags, 'R');
        $directoryItself = str_contains($flags, 'd');
        $err = '';
        $code = 0;
        // Comme ls : d'abord les fichiers nommés (tels qu'on les a écrits), puis le contenu des dossiers.
        $files = [];
        $dirs = [];
        foreach ($targets as $target) {
            $path = $m->path($target);
            if (!$m->fs->exists($path)) {
                $err .= "ls: {$target}: No such file or directory\n";
                $code = $m->facts->os === 'alpine' ? 1 : 2;
                continue;
            }
            if ($m->fs->isDir($path) && !$directoryItself) {
                $dirs[] = [$target, $path];
            } else {
                $files[] = [$target, $path];
            }
        }
        usort($files, static fn ($a, $b) => strcmp($a[0], $b[0]));
        usort($dirs, static fn ($a, $b) => strcmp($a[0], $b[0]));
        $blocks = [];
        if ($files !== []) {
            $blocks[] = $this->lsEntries($files, $m, $long, $human, false, $numeric);
        }
        $headers = \count($targets) > 1 || $recursive;
        while ($dirs !== []) {
            [$target, $path] = array_shift($dirs);
            $names = $m->fs->list($path);
            if (!$hidden) {
                $names = array_values(array_filter($names, static fn ($e) => $e[0] !== '.'));
            }
            $entries = array_map(static fn ($name) => [$name, rtrim($path, '/').'/'.$name], $names);
            if ($dots) {
                array_unshift($entries, ['.', $path], ['..', \dirname($path)]);
            }
            $blocks[] = ($headers ? $target.":\n" : '').$this->lsEntries($entries, $m, $long, $human, true, $numeric);
            if ($recursive) {
                $children = [];
                foreach ($names as $name) {
                    $child = rtrim($path, '/').'/'.$name;
                    if ($m->fs->isDir($child)) {
                        $children[] = [rtrim($target, '/').'/'.$name, $child];
                    }
                }
                array_unshift($dirs, ...$children);
            }
        }

        return new Result($code, implode("\n", $blocks), $err);
    }

    /** @param list<array{0: string, 1: string}> $entries [nom affiché, chemin] */
    private function lsEntries(array $entries, Machine $m, bool $long, bool $human, bool $total, bool $numeric = false): string
    {
        if (!$long) {
            return $entries === [] ? '' : implode("\n", array_column($entries, 0))."\n";
        }
        $out = $total ? 'total '.\count($entries)."\n" : '';
        foreach ($entries as [$name, $child]) {
            $isDir = $m->fs->isDir($child);
            $mode = $m->fs->mode($child);
            $raw = $m->fs->owner($child);
            $owner = $numeric ? (string) ($m->uidOf($raw) ?? $raw) : $m->ownerName($raw);
            $size = $isDir ? 4096 : $m->fs->size($child);
            $out .= sprintf("%s%s %3d %-8s %-8s %8s %s %s\n", $isDir ? 'd' : '-', $this->modeString($mode), $isDir ? 2 : 1, $owner, $owner, $human ? self::human($size) : (string) $size, gmdate('M j H:i'), $name);
        }

        return $out;
    }

    private function modeString(int $mode): string
    {
        $chars = '';
        foreach ([6, 3, 0] as $shift) {
            $bits = ($mode >> $shift) & 7;
            $chars .= ($bits & 4 ? 'r' : '-').($bits & 2 ? 'w' : '-').($bits & 1 ? 'x' : '-');
        }

        return $chars;
    }

    public static function human(int $bytes): string
    {
        foreach (['', 'K', 'M', 'G'] as $unit) {
            if ($bytes < 1024) {
                return $unit === '' ? (string) $bytes : number_format($bytes, $bytes < 10 ? 1 : 0).$unit;
            }
            $bytes /= 1024;
        }

        return number_format($bytes, 1).'T';
    }

    private function mkdir(array $args, Machine $m, Interpreter $sh): Result
    {
        $parents = false;
        $err = '';
        foreach ($args as $arg) {
            if ($arg[0] === '-') {
                $parents = $parents || str_contains($arg, 'p');
                continue;
            }
            $path = $m->path($arg);
            if ($m->fs->exists($path)) {
                if (!$parents) {
                    $err .= "mkdir: can't create directory '{$arg}': File exists\n";
                }
                continue;
            }
            if (!$parents && !$m->fs->isDir(\dirname($path))) {
                $err .= "mkdir: can't create directory '{$arg}': No such file or directory\n";
                continue;
            }
            if (!$sh->canWrite($m, $this->existingAncestor($m, $path))) {
                $err .= "mkdir: can't create directory '{$arg}': ".$sh->writeDenied($m, $path)."\n";
                continue;
            }
            $m->fs->mkdir($path, $m->isRoot() ? null : $m->userName());
        }

        return new Result($err === '' ? 0 : 1, '', $err);
    }

    private function existingAncestor(Machine $m, string $path): string
    {
        while ($path !== '/' && !$m->fs->exists($path)) {
            $path = \dirname($path);
        }

        return $path;
    }

    private function rm(array $args, Machine $m, Interpreter $sh): Result
    {
        $force = false;
        $recursive = false;
        $err = '';
        foreach ($args as $arg) {
            if ($arg !== '' && $arg[0] === '-' && $arg !== '-') {
                $force = $force || str_contains($arg, 'f');
                $recursive = $recursive || str_contains($arg, 'r') || str_contains($arg, 'R');
                continue;
            }
            $path = $m->path($arg);
            if ($path === '/') {
                $err .= "rm: it is dangerous to operate recursively on '/'\n";
                continue;
            }
            if (!$m->fs->exists($path)) {
                if (!$force) {
                    $err .= "rm: can't remove '{$arg}': No such file or directory\n";
                }
                continue;
            }
            if ($m->fs->isDir($path) && !$recursive) {
                $err .= "rm: '{$arg}' is a directory\n";
                continue;
            }
            if (!$sh->canWrite($m, \dirname($path)) && !$sh->canWrite($m, $path)) {
                $err .= "rm: can't remove '{$arg}': ".$sh->writeDenied($m, $path)."\n";
                continue;
            }
            $m->fs->delete($path);
        }

        return new Result($err === '' ? 0 : 1, '', $err);
    }

    private function copy(array $args, Machine $m, Interpreter $sh, bool $move): Result
    {
        $operands = array_values(array_filter($args, static fn ($a) => $a === '' || $a[0] !== '-'));
        $recursive = $move || (bool) array_filter($args, static fn ($a) => preg_match('/^-[a-zA-Z]*[rRa]/', $a));
        $command = $move ? 'mv' : 'cp';
        if (\count($operands) < 2) {
            return Result::error(1, "{$command}: missing file operand\n");
        }
        $destination = $m->path(array_pop($operands));
        $err = '';
        foreach ($operands as $operand) {
            // « cp -r /data/. /backup/ » copie le contenu du dossier, pas le dossier lui-même.
            $contenuSeulement = str_ends_with($operand, '/.') || str_ends_with($operand, '/*');
            $source = $m->path(rtrim(rtrim($operand, '*'), '/.') ?: '/');
            if (!$m->fs->exists($source)) {
                $err .= "{$command}: can't stat '{$operand}': No such file or directory\n";
                continue;
            }
            $target = $m->fs->isDir($destination) && !$contenuSeulement ? rtrim($destination, '/').'/'.basename($source) : $destination;
            if (!$sh->canWrite($m, $this->existingAncestor($m, $target))) {
                // mv ne « crée » rien : BusyBox dit qu'il ne peut pas renommer, GNU qu'il ne peut pas déplacer.
                $err .= !$move
                    ? "cp: can't create '{$target}': ".$sh->writeDenied($m, $target)."\n"
                    : ($m->facts->os === 'alpine'
                        ? "mv: can't rename '{$operand}': ".$sh->writeDenied($m, $target)."\n"
                        : "mv: cannot move '{$operand}' to '{$target}': ".$sh->writeDenied($m, $target)."\n");
                continue;
            }
            if ($m->fs->isDir($source)) {
                if (!$recursive) {
                    $err .= "{$command}: omitting directory '{$operand}'\n";
                    continue;
                }
                $m->fs->mkdir($target);
                foreach ($m->fs->files($source) as $file) {
                    $this->copyFile($m, $file, $target.substr($file, \strlen(rtrim($source, '/'))));
                }
            } else {
                if (!$m->fs->isDir(\dirname($target))) {
                    $err .= "{$command}: can't create '{$target}': No such file or directory\n";
                    continue;
                }
                $this->copyFile($m, $source, $target);
            }
            if ($move) {
                $m->fs->delete($source);
            }
        }

        return new Result($err === '' ? 0 : 1, '', $err);
    }

    private function copyFile(Machine $m, string $source, string $target): void
    {
        if ($m->fs instanceof \Forelse\DockerSim\Fs\MemoryFs) {
            $m->fs->putBlob($target, (string) $m->fs->blob($source), $m->fs->mode($source), $m->isRoot() ? null : $m->userName());

            return;
        }
        $m->fs->write($target, (string) $m->fs->read($source), $m->fs->mode($source), $m->isRoot() ? null : $m->userName());
    }

    private function ln(array $args, Machine $m): Result
    {
        $operands = array_values(array_filter($args, static fn ($a) => $a[0] !== '-'));
        if (\count($operands) < 2) {
            return Result::ok();
        }
        [$source, $target] = [$m->path($operands[0]), $m->path($operands[1])];
        // Lien symbolique simulé par une copie : suffisant pour /usr/share/zoneinfo, /dev/stdout…
        if (str_starts_with($source, '/dev/') || str_starts_with($source, '/proc/')) {
            $m->fs->write($target, '');

            return Result::ok();
        }
        if ($m->fs->isFile($source)) {
            $this->copyFile($m, $source, $target);
        } elseif (!$m->fs->exists($source)) {
            $m->fs->write($target, '');
        }

        return Result::ok();
    }

    private function touch(array $args, Machine $m, Interpreter $sh): Result
    {
        $err = '';
        foreach ($args as $arg) {
            if ($arg[0] === '-') {
                continue;
            }
            $path = $m->path($arg);
            if (!$m->fs->isDir(\dirname($path))) {
                $err .= "touch: {$arg}: No such file or directory\n";
                continue;
            }
            if (!$sh->canWrite($m, $path)) {
                $err .= "touch: {$arg}: ".$sh->writeDenied($m, $path)."\n";
                continue;
            }
            if (!$m->fs->exists($path)) {
                $m->fs->write($path, '', null, $m->isRoot() ? null : $m->userName());
            }
        }

        return new Result($err === '' ? 0 : 1, '', $err);
    }

    private function chmod(array $args, Machine $m): Result
    {
        $recursive = \in_array('-R', $args, true);
        $operands = array_values(array_filter($args, static fn ($a) => $a !== '-R' && $a !== '-v'));
        $spec = array_shift($operands) ?? '';
        $err = '';
        foreach ($operands as $operand) {
            $path = $m->path($operand);
            if (!$m->fs->exists($path)) {
                $err .= "chmod: {$operand}: No such file or directory\n";
                continue;
            }
            if (!$m->isRoot() && !$m->owns($m->fs->owner($path))) {
                $err .= "chmod: {$operand}: Operation not permitted\n";
                continue;
            }
            $paths = $recursive && $m->fs->isDir($path) ? [$path, ...$m->fs->files($path)] : [$path];
            foreach ($paths as $target) {
                $m->fs->chmod($target, $this->applyMode($spec, $m->fs->mode($target), $m->fs->isDir($target)));
            }
        }

        return new Result($err === '' ? 0 : 1, '', $err);
    }

    private function applyMode(string $spec, int $current, bool $isDir): int
    {
        if (preg_match('/^[0-7]{3,4}$/', $spec)) {
            return octdec($spec);
        }
        foreach (explode(',', $spec) as $clause) {
            if (!preg_match('/^([ugoa]*)([+\-=])([rwxX]*)$/', $clause, $match)) {
                continue;
            }
            $who = $match[1] === '' || str_contains($match[1], 'a') ? 'ugo' : $match[1];
            $bits = 0;
            foreach (str_split($match[3]) as $perm) {
                $bits |= match ($perm) { 'r' => 4, 'w' => 2, 'x' => 1, 'X' => $isDir || ($current & 0111) ? 1 : 0, default => 0 };
            }
            $mask = 0;
            foreach (str_split($who) as $w) {
                $mask |= $bits << match ($w) { 'u' => 6, 'g' => 3, default => 0 };
            }
            $current = match ($match[2]) { '+' => $current | $mask, '-' => $current & ~$mask, default => $mask };
        }

        return $current;
    }

    private function chown(array $args, Machine $m, bool $groupOnly): Result
    {
        $recursive = (bool) array_filter($args, static fn ($a) => preg_match('/^-[a-zA-Z]*R/', $a));
        $operands = array_values(array_filter($args, static fn ($a) => $a[0] !== '-'));
        $spec = array_shift($operands) ?? '';
        $user = $groupOnly ? null : explode(':', $spec)[0];
        if ($user !== null && $user !== '' && !ctype_digit($user) && !isset($m->facts->users[$user])) {
            return Result::error(1, "chown: unknown user {$user}\n");
        }
        if ($user !== null && ctype_digit($user)) {
            $user = array_search((int) $user, $m->facts->users, true) ?: $user;
        }
        $err = '';
        foreach ($operands as $operand) {
            $path = $m->path($operand);
            if (!$m->fs->exists($path)) {
                $err .= "chown: {$operand}: No such file or directory\n";
                continue;
            }
            if ($user === null || $user === '') {
                continue;
            }
            $paths = $recursive && $m->fs->isDir($path) ? [$path, ...$m->fs->files($path), ...$this->subdirs($m, $path)] : [$path];
            foreach ($paths as $target) {
                // Sans être root, on ne peut que « redonner » un fichier à soi-même : Linux refuse tout
                // changement de propriétaire, mais laisse faire un chown qui ne change rien.
                if (!$m->isRoot() && (!$m->owns($m->fs->owner($target)) || !$m->owns((string) $user))) {
                    $err .= 'chown: '.($target === $path ? $operand : $target).": Operation not permitted\n";
                    continue;
                }
                $m->fs->chown($target, (string) $user);
            }
        }

        return new Result($err === '' ? 0 : 1, '', $err);
    }

    /** @return list<string> */
    private function subdirs(Machine $m, string $path): array
    {
        $dirs = [];
        foreach ($m->fs->list($path) as $name) {
            $child = rtrim($path, '/').'/'.$name;
            if ($m->fs->isDir($child)) {
                $dirs[] = $child;
                array_push($dirs, ...$this->subdirs($m, $child));
            }
        }

        return $dirs;
    }

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
                    foreach (array_filter($found, static fn ($x) => $x !== '') as $piece) {
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

    private function find(array $args, Machine $m, Interpreter $sh): Result
    {
        $start = $args !== [] && $args[0][0] !== '-' ? array_shift($args) : '.';
        $root = $m->path($start);
        $name = null;
        $type = null;
        $delete = false;
        $exec = null;
        $maxDepth = PHP_INT_MAX;
        for ($i = 0; $i < \count($args); ++$i) {
            switch ($args[$i]) {
                case '-name':
                case '-iname':
                    $name = $args[++$i] ?? '*';
                    break;
                case '-type':
                    $type = $args[++$i] ?? null;
                    break;
                case '-delete':
                    $delete = true;
                    break;
                case '-maxdepth':
                    $maxDepth = (int) ($args[++$i] ?? 0);
                    break;
                case '-exec':
                    $exec = [];
                    while (++$i < \count($args) && $args[$i] !== ';' && $args[$i] !== '+' && $args[$i] !== '\;') {
                        $exec[] = $args[$i];
                    }
                    break;
            }
        }
        if (!$m->fs->exists($root)) {
            return Result::error(1, "find: {$start}: No such file or directory\n");
        }
        $found = [];
        $walk = function (string $dir, int $depth) use (&$walk, &$found, $m, $maxDepth): void {
            if ($depth > $maxDepth) {
                return;
            }
            foreach ($m->fs->list($dir) as $entry) {
                $child = rtrim($dir, '/').'/'.$entry;
                $found[] = [$child, $m->fs->isDir($child), $depth];
                if ($m->fs->isDir($child)) {
                    $walk($child, $depth + 1);
                }
            }
        };
        $found[] = [$root, $m->fs->isDir($root), 0];
        if ($m->fs->isDir($root)) {
            $walk($root, 1);
        }
        $out = '';
        foreach ($found as [$path, $isDir, $depth]) {
            if ($name !== null && !fnmatch($name, basename($path))) {
                continue;
            }
            if ($type === 'f' && $isDir || $type === 'd' && !$isDir) {
                continue;
            }
            $display = $start === '.' ? '.'.substr($path, \strlen(rtrim($root, '/'))) : $path;
            if ($delete) {
                $m->fs->delete($path);
                continue;
            }
            if ($exec !== null) {
                $argv = array_map(static fn ($a) => $a === '{}' ? $path : $a, $exec);
                $result = $sh->invoke($argv, $m);
                $out .= $result->stdout.$result->stderr;
                continue;
            }
            $out .= $display."\n";
        }

        return Result::ok($out);
    }

    private function archive(string $name, array $args, Machine $m): Result
    {
        $m->note(sprintf('« %s » : les archives ne sont pas simulées (commande considérée comme réussie).', $name));

        return Result::ok('', 0.3);
    }

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

    private function xargs(array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        $items = preg_split('/\s+/', trim($stdin), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $args = array_values(array_filter($args, static fn ($a) => !\in_array($a, ['-r', '--no-run-if-empty'], true)));
        if ($items === []) {
            return Result::ok();
        }

        return $sh->invoke([...($args ?: ['echo']), ...$items], $m);
    }

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

    private function du(array $args, Machine $m): Result
    {
        $targets = array_values(array_filter($args, static fn ($a) => $a[0] !== '-')) ?: ['.'];
        $human = (bool) array_filter($args, static fn ($a) => str_contains($a, 'h'));
        $out = '';
        foreach ($targets as $target) {
            $size = $m->fs->size($m->path($target));
            $out .= ($human ? self::human($size) : (string) intdiv($size, 1024))."\t".$target."\n";
        }

        return Result::ok($out);
    }

    private function ps(Machine $m): Result
    {
        $command = $m->env['__SIM_MAIN_PROCESS'] ?? 'sh';

        return Result::ok("PID   USER     TIME  COMMAND\n    1 {$m->userName()}     0:00 {$command}\n   42 {$m->userName()}     0:00 ps\n");
    }

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

    /** kill [-SIGNAL] PID… : seul le processus n° 1 (le conteneur) existe vraiment pour le simulateur. */
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

    private function stat(array $args, Machine $m): Result
    {
        $format = null;
        $files = [];
        for ($i = 0; $i < \count($args); ++$i) {
            if ($args[$i] === '-c' || $args[$i] === '--format') {
                $format = $args[++$i] ?? '%n';
            } elseif (str_starts_with($args[$i], '--format=')) {
                $format = substr($args[$i], 9);
            } elseif ($args[$i] !== '' && $args[$i][0] !== '-') {
                $files[] = $args[$i];
            }
        }
        if ($files === []) {
            return Result::error(1, "stat: missing operand\n");
        }
        $out = '';
        $err = '';
        foreach ($files as $file) {
            $path = $m->path($file);
            if (!$m->fs->exists($path)) {
                $err .= "stat: can't stat '{$file}': No such file or directory\n";
                continue;
            }
            $rawOwner = $m->fs->owner($path);
            $uid = (string) ($m->uidOf($rawOwner) ?? 0);
            $owner = ctype_digit($m->ownerName($rawOwner)) ? 'UNKNOWN' : $m->ownerName($rawOwner);
            $isDir = $m->fs->isDir($path);
            if ($format !== null) {
                $out .= strtr($format, ['%U' => $owner, '%G' => $owner, '%u' => $uid, '%g' => $uid, '%a' => decoct($m->fs->mode($path)), '%A' => ($isDir ? 'd' : '-').$this->modeString($m->fs->mode($path)), '%s' => (string) $m->fs->size($path), '%n' => $file, '%F' => $isDir ? 'directory' : ($m->fs->size($path) === 0 ? 'regular empty file' : 'regular file')])."\n";
                continue;
            }
            $out .= sprintf("  File: %s\n  Size: %d\nAccess: (%04o/%s)  Uid: (%5s/%8s)   Gid: (%5s/%8s)\n", $file, $m->fs->size($path), $m->fs->mode($path), ($isDir ? 'd' : '-').$this->modeString($m->fs->mode($path)), $uid, $owner, $uid, $owner);
        }

        return new Result($err === '' ? 0 : 1, $out, $err);
    }

    private function dateFormat(string $format): string
    {
        return strtr($format, ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%H' => 'H', '%M' => 'i', '%S' => 's', '%s' => 'U', '%F' => 'Y-m-d', '%T' => 'H:i:s']);
    }

    private function wrapper(array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        $args = array_values(array_filter($args, static fn ($a) => $a !== '--' && $a[0] !== '-'));

        return $args === [] ? Result::ok() : $sh->invoke($args, $m, $stdin);
    }

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
