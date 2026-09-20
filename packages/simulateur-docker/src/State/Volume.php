<?php

declare(strict_types=1);

namespace Forelse\DockerSim\State;

final class Volume
{
    /** @param array<string,string> $labels */
    public function __construct(
        public readonly string $name,
        public array $labels = [],
        public int $createdAt = 0,
        public bool $anonymous = false,
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
        return new self($data['name'], $data['labels'] ?? [], $data['createdAt'] ?? 0, $data['anonymous'] ?? false);
    }
}
