<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Fs;

/** Système de fichiers en lecture seule : ce dont ont besoin ceux qui lisent sans écrire (configuration nginx…). */
interface ReadableFileSystem
{
    public function exists(string $path): bool;

    public function isDir(string $path): bool;

    public function isFile(string $path): bool;

    public function read(string $path): ?string;

    /** @return list<string> noms des entrées directes */
    public function list(string $path): array;

    public function size(string $path): int;

    public function mode(string $path): int;

    public function owner(string $path): string;

    /**
     * Chemins de tous les fichiers sous $path (récursif).
     *
     * @return list<string>
     */
    public function files(string $path = '/'): array;
}
