<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Fs;

/**
 * Système de fichiers vu depuis l'intérieur d'une image ou d'un conteneur (chemins absolus).
 * Deux implémentations : en mémoire pendant un build (MemoryFs, qui produit la couche),
 * sur disque pour un conteneur (DiskFs, montages compris).
 */
interface FileSystem extends ReadableFileSystem
{
    /** Vrai si le chemin tombe dans un montage en lecture seule (:ro) : personne n'y écrit, pas même root. */
    public function isReadOnly(string $path): bool;

    public function write(string $path, string $content, ?int $mode = null, ?string $owner = null): void;

    /** Copie un fichier de l'hôte sans le charger (gros fichiers de vendor/). */
    public function writeFromHost(string $path, string $hostPath, ?int $mode = null, ?string $owner = null): void;

    public function mkdir(string $path, ?string $owner = null): void;

    /** Supprime un fichier ou un dossier et son contenu. */
    public function delete(string $path): void;

    public function chmod(string $path, int $mode): void;

    public function chown(string $path, string $owner): void;
}
