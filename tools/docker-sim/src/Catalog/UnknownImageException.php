<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Catalog;

/** Image absente du registre simulé, avec le message que Docker afficherait. */
final class UnknownImageException extends \RuntimeException
{
    public static function repository(string $repository, string $tag): self
    {
        return new self(sprintf('pull access denied for %s, repository does not exist or may require \'docker login\': denied: requested access to the resource is denied', $repository));
    }

    public static function manifest(string $repository, string $tag): self
    {
        return new self(sprintf('manifest for %s not found: manifest unknown: manifest unknown', Catalog::canonical($repository, $tag)));
    }
}
