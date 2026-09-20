<?php

namespace App\Instance;

use App\Content\RepositoryUrl;

/**
 * Un environnement installé depuis un dépôt Git : d'où il vient, où il en est.
 *
 * L'état vit à côté des fichiers (`<id>/.forelse.json`) et non en base : un environnement est un
 * dossier, il se sauvegarde, se copie et s'inspecte comme tel, et une base restaurée sans son volume
 * ne prétend pas connaître des environnements absents.
 */
final readonly class InstalledEnvironment
{
    /** En cours d'installation : le dossier existe, mais l'environnement n'est pas encore jouable. */
    public const string INSTALLING = 'installing';
    /** Installé et empaqueté : le navigateur peut le charger. */
    public const string READY = 'ready';
    /** L'installation ou la construction a échoué ; `message` dit pourquoi. */
    public const string FAILED = 'failed';

    public function __construct(
        public string $id,
        public string $url,
        /** Branche ou étiquette demandée ; vide : la branche par défaut du dépôt. */
        public string $ref,
        /** L'empreinte du commit installé, si le clone a abouti. */
        public string $commit,
        public string $state,
        /** Ce qui s'est passé : la cause d'un échec, ou l'étape en cours. */
        public string $message,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /** L'adresse telle qu'on peut l'afficher : sans le jeton d'un dépôt privé. */
    public function displayUrl(): string
    {
        return RepositoryUrl::withoutCredentials($this->url);
    }

    public function isReady(): bool
    {
        return self::READY === $this->state;
    }

    public function hasFailed(): bool
    {
        return self::FAILED === $this->state;
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'ref' => $this->ref,
            'commit' => $this->commit,
            'state' => $this->state,
            'message' => $this->message,
            'updatedAt' => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            url: (string) ($data['url'] ?? ''),
            ref: (string) ($data['ref'] ?? ''),
            commit: (string) ($data['commit'] ?? ''),
            state: (string) ($data['state'] ?? self::FAILED),
            message: (string) ($data['message'] ?? ''),
            updatedAt: new \DateTimeImmutable((string) ($data['updatedAt'] ?? 'now')),
        );
    }
}
