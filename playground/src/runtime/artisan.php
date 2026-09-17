<?php

/**
 * Exécute une commande artisan dans le projet /app (instance « aperçu », env local)
 * et imprime le code de sortie après le marqueur. Pas de saisie interactive possible.
 * Pendant de console.php pour les environnements Laravel.
 */

const MARKER = "\n@@SORTIE@@";

chdir('/app');
putenv('COLUMNS=110');
require '/app/vendor/autoload.php';

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\StreamOutput;

$arguments = json_decode($_SERVER['CONSOLE_ARGS'] ?? '[]', true) ?: ['list'];
if (!in_array('--no-interaction', $arguments, true) && !in_array('-n', $arguments, true)) {
    $arguments[] = '--no-interaction';
}

try {
    $app = require '/app/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $input = new ArgvInput(['artisan', ...$arguments]);
    $output = new StreamOutput(fopen('php://stdout', 'w'), decorated: true);
    $exitCode = $kernel->handle($input, $output);
    $kernel->terminate($input, $exitCode);
} catch (Throwable $e) {
    // Erreur avant même le démarrage de la console (ex. erreur de syntaxe dans une classe).
    echo get_class($e), ': ', $e->getMessage(), "\n", $e->getFile(), ':', $e->getLine();
    $exitCode = 1;
}

echo MARKER, $exitCode;
