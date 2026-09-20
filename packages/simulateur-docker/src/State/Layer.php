<?php

declare(strict_types=1);

namespace Forelse\DockerSim\State;

/**
 * Une couche d'image : l'instruction qui l'a produite, les fichiers qu'elle ajoute ou retire,
 * sa taille, et la clé de cache qui décide si un prochain build peut la réutiliser.
 */
final class Layer
{
    /**
     * @param array<string,string> $files   chemin absolu dans l'image => contenu
     * @param list<string>         $deleted chemins retirés par cette couche (rm dans un RUN)
     * @param array<string,array{0:int,1:string}> $meta chemin => [mode, propriétaire] (défaut : 0644, root)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $createdBy,
        public readonly int $size,
        public readonly string $cacheKey,
        public readonly array $files = [],
        public readonly array $deleted = [],
        public readonly int $createdAt = 0,
        /** Couche vide de métadonnées (ENV, WORKDIR, CMD…) : 0 octet, pas de fichier. */
        public readonly bool $empty = false,
        public readonly array $meta = [],
        /** Dossiers créés (vides ou non) par la couche. */
        public readonly array $dirs = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'createdBy' => $this->createdBy, 'size' => $this->size, 'cacheKey' => $this->cacheKey, 'files' => $this->files, 'deleted' => $this->deleted, 'createdAt' => $this->createdAt, 'empty' => $this->empty, 'meta' => $this->meta, 'dirs' => $this->dirs];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['createdBy'], $data['size'], $data['cacheKey'], $data['files'] ?? [], $data['deleted'] ?? [], $data['createdAt'] ?? 0, $data['empty'] ?? false, $data['meta'] ?? [], $data['dirs'] ?? []);
    }
}
