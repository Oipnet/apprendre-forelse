<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Cli;

use Forelse\DockerSim\Compose\ComposeFile;

/**
 * Exécute un script de commandes docker (lancer.sh, un Makefile simplifié…) : chaque ligne qui commence
 * par « docker » passe par la CLI du simulateur, dans l'ordre ; les commentaires, set -e et les lignes
 * vides sont ignorés. Continuations « \ » recollées. Au premier échec, le script s'arrête (comme set -e).
 */
final class ScriptRunner
{
    /** @return list<string> commandes (sans « docker ») */
    public static function commands(string $script): array
    {
        $script = str_replace(["\\\r\n", "\\\n"], ' ', $script);
        $commands = [];
        foreach (preg_split('/\r?\n/', $script) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || preg_match('/^(set\s+-|#!)/', $line)) {
                continue;
            }
            foreach (preg_split('/\s*(?:&&|;)\s*/', $line) ?: [] as $part) {
                $part = trim($part);
                // « APP_ENV=dev docker compose up » : les variables posées devant la commande la suivent.
                $prefix = '';
                while (preg_match('/^([A-Za-z_][A-Za-z0-9_]*=(?:"[^"]*"|\'[^\']*\'|\S*))\s+(?=\S)/', $part, $v)) {
                    $prefix .= $v[1].' ';
                    $part = substr($part, \strlen($v[0]));
                }
                if (preg_match('/^(docker(?:-compose)?)\s+(.*)$/', $part, $m)) {
                    $commands[] = $prefix.($m[1] === 'docker-compose' ? 'compose ' : '').$m[2];
                } elseif ($part !== '') {
                    $part = $prefix.$part;
                    $commands[] = '#'.$part;
                }
            }
        }

        return $commands;
    }

    /** @return array{0: int, 1: string} */
    public static function run(Application $application, string $script): array
    {
        $output = '';
        if (array_filter(self::commands($script), static fn (string $c) => !str_starts_with($c, '#')) === []) {
            return [0, "💡 Le script ne contient encore aucune commande docker (une par ligne, par exemple : docker run …).\n"];
        }
        foreach (self::commands($script) as $command) {
            if (str_starts_with($command, '#')) {
                $output .= sprintf("💡 « %s » n'est pas une commande docker : le simulateur l'ignore.\n", substr($command, 1));
                continue;
            }
            $prefix = '';
            while (preg_match('/^([A-Za-z_][A-Za-z0-9_]*=(?:"[^"]*"|\'[^\']*\'|\S*))\s+(?=\S)/', $command, $v)) {
                $prefix .= $v[1].' ';
                $command = substr($command, \strlen($v[0]));
            }
            $output .= '$ '.$prefix.'docker '.$command."\n";
            $application->output = '';
            $code = $application->run(ComposeFile::shellSplit($prefix.$command));
            $output .= $application->output;
            if ($code !== 0) {
                return [$code, $output];
            }
        }

        return [0, $output];
    }
}
