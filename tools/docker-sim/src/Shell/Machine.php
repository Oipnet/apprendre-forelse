<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell;

use Forelse\DockerSim\Fs\FileSystem;

/** L'environnement dans lequel une commande shell s'exécute : fichiers, faits, variables, réseau. */
final class Machine
{
    public const BUILD = 'build';
    public const EXEC = 'exec';

    public string $output = '';
    public float $elapsed = 0.0;
    public bool $errexit = false;
    public bool $xtrace = false;
    /** Commande à exécuter à la place du shell (exec "$@" dans un script d'entrée). @var list<string>|null */
    public ?array $execTarget = null;
    /** Positional parameters ($1, $@) du script en cours. @var list<string> */
    public array $positional = [];
    /** Messages d'information du simulateur (ce qu'il n'exécute pas vraiment). @var list<string> */
    public array $notes = [];
    /** Signaux envoyés au processus principal pendant un docker exec (« nginx -s reload »). @var list<string> */
    public array $signals = [];

    /**
     * @param array<string,string> $env
     * @param (callable(list<string> $argv, string $cwd, array<string,string> $env, string $stdin): array{0:int,1:string})|null $php
     *        exécute un script PHP pour de vrai (conteneur en cours d'exécution)
     */
    public function __construct(
        public FileSystem $fs,
        public Facts $facts,
        public array $env,
        public string $cwd = '/',
        public string $user = 'root',
        public string $mode = self::BUILD,
        public ?Network $network = null,
        public $php = null,
        public string $hostname = 'buildkitsandbox',
        /** Dossier du projet sur l'hôte (contexte de build), pour copier les paquets de vendor/. */
        public ?string $hostProject = null,
    ) {
    }

    public function isRoot(): bool
    {
        return $this->user === 'root' || $this->user === '0' || str_starts_with($this->user, '0:') || str_starts_with($this->user, 'root:');
    }

    public function userName(): string
    {
        return explode(':', $this->user)[0];
    }

    /** Le numéro d'un utilisateur (« 82 » ou « www-data »), s'il est connu de l'image. */
    public function uidOf(string $owner): ?int
    {
        return ctype_digit($owner) ? (int) $owner : ($this->facts->users[$owner] ?? null);
    }

    /** Le nom d'un propriétaire tel que ls ou stat l'affichent : « 82 » devient www-data si l'image le connaît. */
    public function ownerName(string $owner): string
    {
        return ctype_digit($owner) ? ((string) (array_search((int) $owner, $this->facts->users, true) ?: $owner)) : $owner;
    }

    /** Le fichier appartient-il à l'utilisateur courant ? Les noms et les numéros se valent (chown 82 = chown www-data). */
    public function owns(string $owner): bool
    {
        if ($owner === $this->userName()) {
            return true;
        }
        $uid = $this->uidOf($owner);

        return $uid !== null && $uid === $this->uidOf($this->userName());
    }

    public function path(string $path): string
    {
        return \Forelse\DockerSim\Fs\Path::normalize($path, $this->cwd);
    }

    public function note(string $message): void
    {
        $this->notes[] = $message;
    }
}
