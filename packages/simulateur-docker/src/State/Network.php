<?php

declare(strict_types=1);

namespace Forelse\DockerSim\State;

final class Network
{
    /** @param array<string,string> $labels */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $driver,
        public readonly string $subnet,
        public array $labels = [],
        public int $createdAt = 0,
        /** Le réseau « bridge » par défaut n'a pas de résolution de noms entre conteneurs. */
        public bool $builtin = false,
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
        return new self($data['id'], $data['name'], $data['driver'], $data['subnet'], $data['labels'] ?? [], $data['createdAt'] ?? 0, $data['builtin'] ?? false);
    }
}
