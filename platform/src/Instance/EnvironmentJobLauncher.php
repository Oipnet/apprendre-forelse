<?php

namespace App\Instance;

/**
 * Lance en tâche de fond une commande d'installation ou d'empaquetage d'environnements, et rend la main tout de suite :
 * le clone et le `composer install` dépassent de loin le temps d'une requête. Le suivi passe par l'état écrit sur disque.
 */
interface EnvironmentJobLauncher
{
    /** @param list<string> $arguments */
    public function launch(string $command, array $arguments): void;
}
