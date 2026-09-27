<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell\Command;

use Forelse\DockerSim\Shell\Interpreter;
use Forelse\DockerSim\Shell\Machine;
use Forelse\DockerSim\Shell\Result;

/** Les utilitaires de fichiers (coreutils / busybox) : lister, créer, copier, droits, recherche, place occupée. */
final class FileCommands extends CoreutilsCommands
{
    public function names(): array
    {
        return ['cat', 'ls', 'mkdir', 'rm', 'rmdir', 'cp', 'mv', 'ln', 'touch', 'chmod', 'chown', 'chgrp', 'find', 'du', 'df', 'stat', 'file', 'less', 'more', 'readlink', 'realpath', 'basename', 'dirname'];
    }

    /** @param list<string> $args */
    protected function dispatch(string $name, array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        return match ($name) {
            'cat', 'less', 'more' => $this->cat($args, $m, $stdin),
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
            'find' => $this->find($args, $m, $sh),
            'du' => $this->du($args, $m),
            'df' => Result::ok("Filesystem           1K-blocks      Used Available Use% Mounted on\noverlay               61202244  18034412  40026508  31% /\n"),
            'stat' => $this->stat($args, $m),
            'file' => Result::ok(($args[0] ?? '').': '.($m->fs->isDir($m->path($args[0] ?? '')) ? 'directory' : 'ASCII text')."\n"),
            'readlink', 'realpath' => Result::ok($m->path(end($args) ?: '.')."\n"),
            'basename' => Result::ok(basename($args[0] ?? '', $args[1] ?? '')."\n"),
            'dirname' => Result::ok(\dirname($args[0] ?? '.')."\n"),
            default => throw new \LogicException(sprintf('%s : « %s » est déclarée sans implémentation.', self::class, $name)),
        };
    }

    /** @param list<string> $args */
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

    /** @param list<string> $args */
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

    /** @param list<string> $args */
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

    /** @param list<string> $args */
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

    /** @param list<string> $args */
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

    /** @param list<string> $args */
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

    /** @param list<string> $args */
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

    /** @param list<string> $args */
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

    /** @param list<string> $args */
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

    /** @param list<string> $args */
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

    /** @param list<string> $args */
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

    /** @param list<string> $args */
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
}
