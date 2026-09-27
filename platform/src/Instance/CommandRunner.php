<?php

namespace App\Instance;

use App\Content\ContentException;
use Symfony\Component\Process\Process;

/** Lance une commande (un tableau d'arguments, jamais un shell) et rend sa sortie, ou l'erreur qui dit ce qui manque. */
final class CommandRunner
{
    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     */
    public function run(array $command, ?string $cwd, int $timeout, string $etape, array $env = []): string
    {
        $process = new Process($command, $cwd, $env ?: null, timeout: $timeout);
        $process->run();
        if (!$process->isSuccessful()) {
            // La sortie d'erreur d'un git ou d'un composer dit précisément ce qui manque : on la garde,
            // tronquée, plutôt que de la remplacer par « échec ».
            $sortie = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw new ContentException(sprintf('%s : échec. %s', $etape, mb_substr($sortie, -2000)));
        }

        return $process->getOutput();
    }
}
