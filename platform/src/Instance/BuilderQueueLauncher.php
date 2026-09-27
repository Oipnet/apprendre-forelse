<?php

namespace App\Instance;

/** Confie la commande au service empaqueteur (ENVIRONMENTS_BUILDER=empaqueteur) : rien ne s'exécute ici. */
final readonly class BuilderQueueLauncher implements EnvironmentJobLauncher
{
    public function __construct(private EnvironmentBuildQueue $queue)
    {
    }

    public function launch(string $command, array $arguments): void
    {
        $this->queue->push($command, $arguments);
    }
}
