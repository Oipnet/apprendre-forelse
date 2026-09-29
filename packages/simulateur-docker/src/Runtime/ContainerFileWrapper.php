<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Runtime;

/**
 * Le système de fichiers vu par le PHP d'un conteneur : remplace le flux « file:// » le temps du script.
 *
 * Un chemin absolu du conteneur (/data/compteur.txt) est ramené sous sa racine sur disque, où les montages sont
 * des liens vers le dossier de l'hôte ou du volume ; sans cela, il visait le disque de l'hôte (ou de php-wasm),
 * et un volume ne gardait rien. Les chemins déjà réels (le script lui-même, __DIR__, les sources des montages)
 * passent tels quels. Une écriture sous un montage « :ro » est refusée : « Read-only file system ».
 *
 * Limite : ce qui ouvre ses fichiers sans passer par les flux PHP (SQLite dans new PDO('sqlite:/…')) n'est pas
 * traduit. Un DSN passé par une variable d'environnement l'est déjà (PhpExecutor::translateValue()).
 */
final class ContainerFileWrapper
{
    /** @var resource|null */
    public $context;

    private static string $root = '';
    /** @var list<string> préfixes réels laissés tels quels */
    private static array $real = [];
    /** @var list<string> préfixes réels en lecture seule */
    private static array $readOnly = [];
    /** Pourquoi la dernière ouverture a échoué, telle que PHP l'écrirait (voir failure()). */
    private static string $failure = '';

    /** @var resource|null */
    private $handle;

    /**
     * @param list<string> $real     préfixes réels à laisser passer (racine, sources des montages)
     * @param list<string> $readOnly préfixes réels où rien ne s'écrit
     */
    public static function activate(string $root, array $real, array $readOnly): void
    {
        self::$root = rtrim($root, '/');
        self::$real = array_values(array_filter(array_map(static fn (string $p) => rtrim($p, '/'), [$root, ...$real])));
        self::$readOnly = array_values(array_filter(array_map(static fn (string $p) => rtrim($p, '/'), $readOnly)));
        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);
    }

    public static function deactivate(): void
    {
        @stream_wrapper_restore('file');
    }

    /** Le chemin réel d'un chemin vu par le script. */
    public static function map(string $path): string
    {
        if (str_starts_with($path, 'file://')) {
            $path = substr($path, 7);
        }
        if (!str_starts_with($path, '/')) {
            return $path; // relatif : résolu depuis le dossier courant, déjà dans le conteneur
        }
        foreach (self::$real as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return $path;
            }
        }

        return self::$root.$path;
    }

    private static function readOnly(string $real): bool
    {
        $absolute = str_starts_with($real, '/') ? $real : getcwd().'/'.$real;
        foreach (self::$readOnly as $prefix) {
            if ($absolute === $prefix || str_starts_with($absolute, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pour un flux utilisateur, PHP écrit « "…::stream_open" call failed » : le message devient celui que PHP écrirait sous
     * Linux (« Read-only file system », « No such file or directory »…).
     */
    public static function rewrite(string $message): string
    {
        return (string) preg_replace('/"'.preg_quote(self::class, '/').'::\w+" call failed/', self::$failure !== '' ? self::$failure : 'No such file or directory', $message);
    }

    /** Refus d'écriture : le message de PHP sous Linux. */
    private static function denied(string $function, string $path): bool
    {
        self::$failure = 'Read-only file system';
        trigger_error(sprintf('%s(%s): Failed to open stream: Read-only file system', $function, $path), \E_USER_WARNING);

        return false;
    }

    /**
     * Une opération du vrai flux « file:// », le temps de l'appel.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private static function native(callable $operation): mixed
    {
        stream_wrapper_restore('file');
        try {
            return $operation();
        } finally {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $real = self::map($path);
        self::$failure = '';
        if (strpbrk($mode, 'waxc+') !== false && self::readOnly($real)) {
            // PHP écrit lui-même l'avertissement (voir rewrite()) : « file_put_contents(…): Failed to open stream: … ».
            self::$failure = 'Read-only file system';

            return false;
        }
        error_clear_last();
        $handle = self::native(fn () => @fopen($real, $mode, ($options & \STREAM_USE_PATH) !== 0, $this->context));
        if ($handle === false) {
            $erreur = error_get_last()['message'] ?? '';
            self::$failure = str_contains($erreur, 'Failed to open stream: ') ? substr($erreur, strpos($erreur, 'Failed to open stream: ') + 23) : '';

            return false;
        }
        $this->handle = $handle;
        $openedPath = $real;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->handle, $count);
    }

    public function stream_write(string $data): int|false
    {
        return fwrite($this->handle, $data);
    }

    public function stream_close(): void
    {
        fclose($this->handle);
    }

    public function stream_eof(): bool
    {
        return feof($this->handle);
    }

    public function stream_flush(): bool
    {
        return fflush($this->handle);
    }

    public function stream_seek(int $offset, int $whence = \SEEK_SET): bool
    {
        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_tell(): int|false
    {
        return ftell($this->handle);
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return fstat($this->handle);
    }

    public function stream_lock(int $operation): bool
    {
        return flock($this->handle, $operation);
    }

    public function stream_truncate(int $size): bool
    {
        return ftruncate($this->handle, $size);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    /** @return resource */
    public function stream_cast(int $as)
    {
        return $this->handle;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        $real = self::map($path);
        if (self::readOnly($real)) {
            return self::denied($option === \STREAM_META_TOUCH ? 'touch' : 'chmod', $path);
        }

        return self::native(static fn () => match ($option) {
            \STREAM_META_TOUCH => touch($real, ...array_values((array) $value)),
            \STREAM_META_OWNER, \STREAM_META_OWNER_NAME => chown($real, $value),
            \STREAM_META_GROUP, \STREAM_META_GROUP_NAME => chgrp($real, $value),
            \STREAM_META_ACCESS => chmod($real, $value),
            default => false,
        });
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        $real = self::map($path);

        return self::native(static fn () => ($flags & \STREAM_URL_STAT_LINK) !== 0 ? @lstat($real) : @stat($real));
    }

    public function unlink(string $path): bool
    {
        $real = self::map($path);

        return self::readOnly($real) ? self::denied('unlink', $path) : self::native(static fn () => unlink($real));
    }

    public function rename(string $from, string $to): bool
    {
        [$realFrom, $realTo] = [self::map($from), self::map($to)];
        if (self::readOnly($realFrom) || self::readOnly($realTo)) {
            return self::denied('rename', $to);
        }

        return self::native(static fn () => rename($realFrom, $realTo));
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        $real = self::map($path);

        return self::readOnly($real) ? self::denied('mkdir', $path) : self::native(static fn () => mkdir($real, $mode, ($options & \STREAM_MKDIR_RECURSIVE) !== 0));
    }

    public function rmdir(string $path, int $options): bool
    {
        $real = self::map($path);

        return self::readOnly($real) ? self::denied('rmdir', $path) : self::native(static fn () => rmdir($real));
    }

    public function dir_opendir(string $path, int $options): bool
    {
        $real = self::map($path);
        $handle = self::native(static fn () => @opendir($real));
        if ($handle === false) {
            return false;
        }
        $this->handle = $handle;

        return true;
    }

    public function dir_readdir(): string|false
    {
        return readdir($this->handle);
    }

    public function dir_rewinddir(): bool
    {
        rewinddir($this->handle);

        return true;
    }

    public function dir_closedir(): bool
    {
        closedir($this->handle);

        return true;
    }
}
