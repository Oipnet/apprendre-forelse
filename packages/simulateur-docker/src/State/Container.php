<?php

declare(strict_types=1);

namespace Forelse\DockerSim\State;

/** Un conteneur : créé depuis une image, avec sa configuration d'exécution et son état. */
final class Container
{
    public const CREATED = 'created';
    public const RUNNING = 'running';
    public const EXITED = 'exited';
    public const RESTARTING = 'restarting';

    /**
     * @param list<string>                                                          $command    ENTRYPOINT + CMD effectifs
     * @param array<string,string>                                                  $env
     * @param list<array{host: int, container: int, protocol: string, ip: string}>  $ports
     * @param list<array{type: 'bind'|'volume', source: string, target: string, readOnly: bool}> $mounts
     * @param array<string, list<string>>                                           $networks   réseau => alias
     * @param array<string,string>                                                  $labels
     * @param list<string>                                                          $logs
     * @param list<int>                                                             $listening  ports sur lesquels un processus écoute (dans le conteneur)
     * @param array{test: list<string>, interval?: string, timeout?: string, startPeriod?: string, retries?: int}|null $healthcheck
     */
    public function __construct(
        public readonly string $id,
        public string $name,
        public readonly string $imageId,
        public readonly string $imageRef,
        public array $command,
        public array $env,
        public string $workdir,
        public ?string $user,
        public array $ports,
        public array $mounts,
        public array $networks,
        public array $labels,
        public string $restart = 'no',
        public ?array $healthcheck = null,
        public bool $autoRemove = false,
        public string $status = self::CREATED,
        public int $exitCode = 0,
        public int $createdAt = 0,
        public int $startedAt = 0,
        public int $finishedAt = 0,
        public array $logs = [],
        public array $listening = [],
        /** Adresse d'écoute du serveur (0.0.0.0, 127.0.0.1…), par port. */
        public array $listenAddresses = [],
        /** Ce qui tourne : apache, nginx, php-fpm, php-server, postgres… ou null. */
        public ?string $process = null,
        public ?string $health = null,
        public string $hostname = '',
        /** Adresses IP par réseau. */
        public array $ips = [],
        public ?string $error = null,
        /** Options du processus (php -S : docroot, routeur). @var array<string,mixed> */
        public array $processOptions = [],
        /** Paquets, extensions, binaires : ceux de l'image, plus ce qui a été installé dans le conteneur. @var array<string,mixed>|null */
        public ?array $facts = null,
        /** Sortie du dernier healthcheck. */
        public string $healthLog = '',
        public bool $tty = false,
        public int $restartCount = 0,
    ) {
    }

    public function shortId(): string
    {
        return substr($this->id, 0, 12);
    }

    public function isRunning(): bool
    {
        return $this->status === self::RUNNING;
    }

    public function composeProject(): ?string
    {
        return $this->labels['com.docker.compose.project'] ?? null;
    }

    public function composeService(): ?string
    {
        return $this->labels['com.docker.compose.service'] ?? null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $container = new self($data['id'], $data['name'], $data['imageId'], $data['imageRef'], $data['command'], $data['env'], $data['workdir'], $data['user'], $data['ports'], $data['mounts'], $data['networks'], $data['labels']);
        foreach ($data as $key => $value) {
            if (!\in_array($key, ['id', 'imageId', 'imageRef'], true) && property_exists($container, $key)) {
                $container->{$key} = $value;
            }
        }

        return $container;
    }
}
