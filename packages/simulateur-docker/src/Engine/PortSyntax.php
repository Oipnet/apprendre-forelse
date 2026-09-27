<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Engine;

/**
 * La forme courte d'un port publié, telle que `docker run -p` et les `ports:` de Compose l'écrivent :
 * `[ip:][hôte:]conteneur[/protocole]`, chaque port pouvant être une plage (`8000-8002:8000-8002`).
 *
 * Un seul analyseur pour la CLI et Compose : les deux en avaient chacun un, qui avaient divergé
 * (Compose ne publiait que le premier port d'une plage, la CLI les refusait).
 */
final class PortSyntax
{
    /**
     * @return list<array{host: ?int, container: int, protocol: string, ip: string}> host null : un port de l'hôte au hasard
     *
     * @throws DockerException la forme est invalide (erreur du client, code 125)
     */
    public static function parse(string $spec): array
    {
        $protocol = 'tcp';
        $rest = $spec;
        if (str_contains($rest, '/')) {
            [$rest, $protocol] = explode('/', $rest, 2);
        }
        $parts = explode(':', $rest);
        $ip = '0.0.0.0';
        if (\count($parts) === 3) {
            $ip = array_shift($parts) ?: '0.0.0.0';
        }
        if (\count($parts) > 2) {
            throw new DockerException(sprintf('invalid publish spec: %s', $spec), 125, false);
        }
        $containers = self::range($parts[\count($parts) - 1], 'containerPort', 1);
        // « 127.0.0.1::80 » : une adresse, mais pas de port de l'hôte imposé.
        $hosts = \count($parts) === 2 && $parts[0] !== '' ? self::range($parts[0], 'hostPort', 0) : null;

        if ($hosts !== null && \count($hosts) !== \count($containers)) {
            // Une plage de l'hôte pour un seul port du conteneur : Docker en prend un libre, le premier ici.
            if (\count($containers) !== 1) {
                throw new DockerException(sprintf('invalid ranges specified for container and host Ports: %s and %s', $parts[1], $parts[0]), 125, false);
            }
            $hosts = [$hosts[0]];
        }

        $bindings = [];
        foreach ($containers as $i => $container) {
            $bindings[] = ['host' => $hosts[$i] ?? null, 'container' => $container, 'protocol' => $protocol, 'ip' => $ip];
        }

        return $bindings;
    }

    /** @return non-empty-list<int> */
    private static function range(string $value, string $what, int $min): array
    {
        $bounds = explode('-', $value);
        if (\count($bounds) > 2 || array_filter($bounds, static fn (string $b) => !ctype_digit($b) || (int) $b < $min || (int) $b > 65535) !== []) {
            throw new DockerException(sprintf('invalid %s: %s', $what, $value), 125, false);
        }
        $start = (int) $bounds[0];
        $end = (int) ($bounds[1] ?? $start);
        if ($end < $start) {
            throw new DockerException(sprintf('invalid range specified for %s: %s', $what, $value), 125, false);
        }

        return range($start, $end);
    }
}
