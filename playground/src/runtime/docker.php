<?php

/**
 * Exécute une commande docker (le simulateur de tools/docker-sim) dans le projet /app, instance « aperçu »,
 * et imprime le code de sortie après le marqueur. Pendant de console.php pour les environnements Docker.
 */

const MARKER = "\n@@SORTIE@@";

chdir('/app');
putenv('COLUMNS=110');
putenv('DOCKER_SIM_INPROCESS=1');
require '/app/vendor/autoload.php';

use Forelse\DockerSim\Cli\Application;

$arguments = json_decode($_SERVER['CONSOLE_ARGS'] ?? '[]', true) ?: [];
// Tolère « docker compose … » tapé en entier, et l'ancien « docker-compose ».
if (($arguments[0] ?? null) === 'docker') {
    array_shift($arguments);
}
if (($arguments[0] ?? null) === 'docker-compose') {
    $arguments[0] = 'compose';
}

$GLOBALS['__docker_done'] = false;
$application = new Application('/tmp/docker-sim', '/app');
// Un script PHP exécuté dans un conteneur peut appeler exit() : on imprime quand même le marqueur.
register_shutdown_function(static function () use ($application): void {
    if (!$GLOBALS['__docker_done']) {
        $application->docker->save();
        echo $application->output, MARKER, 0;
    }
});

try {
    // « sh lancer.sh », « ./lancer.sh » : un script de commandes docker du projet.
    $script = null;
    if (in_array($arguments[0] ?? '', ['sh', 'bash'], true) && isset($arguments[1])) {
        $script = $arguments[1];
    } elseif (isset($arguments[0]) && str_ends_with($arguments[0], '.sh')) {
        $script = $arguments[0];
    }
    if ($script !== null) {
        $path = '/app/'.ltrim(preg_replace('#^\./#', '', $script), '/');
        if (!is_file($path)) {
            $application->output = "sh: can't open '{$script}': No such file or directory\n";
            $exitCode = 2;
        } else {
            [$exitCode, $application->output] = Forelse\DockerSim\Cli\ScriptRunner::run($application, (string) file_get_contents($path));
        }
    } else {
        $exitCode = $application->run($arguments);
    }
} catch (Throwable $e) {
    $application->output .= get_class($e).': '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
    $exitCode = 1;
}
$GLOBALS['__docker_done'] = true;
echo $application->output, MARKER, $exitCode;
