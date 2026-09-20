<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Engine;

/** Tout ce qu'il faut pour créer un conteneur (docker run, docker create, un service de compose). */
final class ContainerSpec
{
    /** @var list<string>|null remplace le CMD de l'image */
    public ?array $command = null;
    /** @var list<string>|null remplace l'ENTRYPOINT ([] : le vide) */
    public ?array $entrypoint = null;
    /** @var array<string,string> */
    public array $env = [];
    /** @var list<array{host: int, container: int, protocol: string, ip: string}> */
    public array $ports = [];
    /** @var list<array{type: 'bind'|'volume', source: string, target: string, readOnly: bool}> source vide : volume anonyme */
    public array $mounts = [];
    /** @var array<string, list<string>> réseau => alias */
    public array $networks = [];
    public ?string $name = null;
    public ?string $workdir = null;
    public ?string $user = null;
    public string $restart = 'no';
    /** @var array<string,mixed>|null */
    public ?array $healthcheck = null;
    /** @var array<string,string> */
    public array $labels = [];
    public bool $autoRemove = false;
    public ?string $hostname = null;
    public bool $tty = false;
    public bool $interactive = false;
    /** Publier tous les ports EXPOSE sur des ports aléatoires (-P). */
    public bool $publishAll = false;

    public function __construct(public string $image)
    {
    }
}
