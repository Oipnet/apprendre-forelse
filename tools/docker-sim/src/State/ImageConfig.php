<?php

declare(strict_types=1);

namespace Forelse\DockerSim\State;

/** La configuration d'une image (ce que docker inspect montre sous « Config »). */
final class ImageConfig
{
    /**
     * @param array<string,string> $env
     * @param list<string>|null    $cmd
     * @param list<string>|null    $entrypoint
     * @param list<string>         $exposed    « 80/tcp »
     * @param list<string>         $volumes
     * @param array<string,string> $labels
     * @param array{test: list<string>, interval?: string, timeout?: string, startPeriod?: string, retries?: int}|null $healthcheck
     */
    public function __construct(
        public array $env = [],
        public ?array $cmd = null,
        public ?array $entrypoint = null,
        public string $workdir = '/',
        public ?string $user = null,
        public array $exposed = [],
        public array $volumes = [],
        public array $labels = [],
        public ?array $healthcheck = null,
        public ?string $stopSignal = null,
        /** Forme shell du CMD/ENTRYPOINT (pour afficher /bin/sh -c …). */
        public array $shell = ['/bin/sh', '-c'],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $config = new self();
        foreach ($data as $key => $value) {
            if (property_exists($config, $key)) {
                $config->{$key} = $value;
            }
        }

        return $config;
    }
}
