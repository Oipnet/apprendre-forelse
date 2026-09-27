<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Engine;

/**
 * La forme courte d'un montage, telle que `docker run -v` et les `volumes:` de Compose l'écrivent :
 * `[source:]cible[:options]`. Une source qui commence par /, . ou ~ est un dossier de l'hôte (bind),
 * sinon un volume nommé ; sans source, un volume anonyme.
 *
 * Un seul analyseur pour la CLI et Compose, qui avaient divergé : seule la CLI refusait une section
 * vide ou une cible relative. Seule la résolution d'un chemin de l'hôte leur reste propre (le dossier
 * courant, ou celui du projet Compose).
 */
final class VolumeSyntax
{
    /**
     * @param callable(string): string $hostPath chemin absolu d'une source de l'hôte (./data, ~/x, /srv)
     *
     * @return array{type: 'bind'|'volume', source: string, target: string, readOnly: bool}
     *
     * @throws DockerException la forme est invalide
     */
    public static function parse(string $spec, callable $hostPath): array
    {
        $parts = explode(':', $spec);
        if (\in_array('', $parts, true)) {
            throw new DockerException(sprintf('invalid spec: %s: empty section between colons', $spec), 125, false);
        }
        if (\count($parts) > 3) {
            throw new DockerException(sprintf('invalid volume specification: \'%s\'', $spec), 125, false);
        }
        if (\count($parts) === 1) {
            return ['type' => 'volume', 'source' => '', 'target' => self::target($spec, 'volume', $parts[0]), 'readOnly' => false];
        }
        [$source, $target] = $parts;
        $readOnly = \in_array('ro', explode(',', $parts[2] ?? ''), true);
        if (str_starts_with($source, '/') || str_starts_with($source, '.') || str_starts_with($source, '~')) {
            return ['type' => 'bind', 'source' => $hostPath($source), 'target' => self::target($spec, 'bind', $target), 'readOnly' => $readOnly];
        }

        return ['type' => 'volume', 'source' => $source, 'target' => self::target($spec, 'volume', $target), 'readOnly' => $readOnly];
    }

    private static function target(string $spec, string $type, string $target): string
    {
        if (!str_starts_with($target, '/')) {
            throw new DockerException(sprintf('invalid volume specification: \'%s\': invalid mount config for type "%s": invalid mount path: \'%s\' mount path must be absolute', $spec, $type, $target));
        }

        return $target;
    }
}
