<?php

namespace App\Instance;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** Lance la commande dans ce conteneur, détachée de la requête (sans service empaqueteur). */
final readonly class DetachedConsoleLauncher implements EnvironmentJobLauncher
{
    public function __construct(private ConsoleCommandLine $console)
    {
    }

    public function launch(string $command, array $arguments): void
    {
        $commande = $this->console->of($command, $arguments);

        // Detacher pour de vrai. Le destructeur de Process **tue** le processus lancé : à la fin de
        // cette méthode, une installation démarrée par `start()` seul serait coupée net. `setsid --fork`
        // la sort de ce groupe de processus — le fils immédiat rend la main aussitôt, le petit-fils
        // continue seul. Jamais de shell : l'adresse vient d'un formulaire, elle reste un argument.
        $setsid = (new ExecutableFinder())->find('setsid');
        $process = new Process(null === $setsid ? $commande : [$setsid, '--fork', ...$commande], $this->console->projectDir);
        $process->setTimeout(null);
        $process->disableOutput();
        $process->run();
    }
}
