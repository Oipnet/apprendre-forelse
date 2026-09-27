<?php

namespace App\Instance;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Le lanceur choisi par ENVIRONMENTS_BUILDER : le service empaqueteur s'il est configuré (le code du dépôt ne tourne
 * pas ici, à côté des secrets), sinon un processus détaché dans ce conteneur.
 */
#[AsAlias(EnvironmentJobLauncher::class)]
final readonly class ConfiguredJobLauncher implements EnvironmentJobLauncher
{
    public function __construct(
        private EnvironmentBuildQueue $queue,
        private BuilderQueueLauncher $builder,
        private DetachedConsoleLauncher $detached,
    ) {
    }

    public function launch(string $command, array $arguments): void
    {
        ($this->queue->isEnabled() ? $this->builder : $this->detached)->launch($command, $arguments);
    }
}
