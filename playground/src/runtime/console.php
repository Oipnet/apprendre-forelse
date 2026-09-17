<?php

/**
 * Exécute une commande bin/console dans le projet /app (instance « aperçu », env dev)
 * et imprime le code de sortie après le marqueur. Pas de saisie interactive possible.
 */

const MARKER = "\n@@SORTIE@@";

chdir('/app');
putenv('COLUMNS=110');
require '/app/vendor/autoload.php';

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Dotenv\Dotenv;

(new Dotenv())->bootEnv('/app/.env');
$arguments = json_decode($_SERVER['CONSOLE_ARGS'] ?? '[]', true) ?: ['list'];

try {
    $kernel = new App\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
    $application = new Application($kernel);
    $application->setAutoExit(false);
    $input = new ArgvInput(['bin/console', ...$arguments]);
    $input->setInteractive(false);
    $exitCode = $application->run($input, new StreamOutput(fopen('php://stdout', 'w'), decorated: true));
} catch (Throwable $e) {
    // Erreur avant même le démarrage de la console (ex. erreur de syntaxe dans une classe).
    echo get_class($e), ': ', $e->getMessage(), "\n", $e->getFile(), ':', $e->getLine();
    $exitCode = 1;
}

echo MARKER, $exitCode;
