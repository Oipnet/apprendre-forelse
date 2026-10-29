<?php

/**
 * Exécute un script du projet /app comme « php script.php arguments » dans un terminal (instance « aperçu »), et
 * imprime le code de sortie après le marqueur. Pendant de console.php pour les projets sans framework : le script
 * reçoit $argv et charge lui-même vendor/autoload.php, comme en ligne de commande.
 */

const MARKER = "\n@@SORTIE@@";

chdir('/app');
putenv('COLUMNS=110');
// Un serveur web n'a ni STDOUT ni STDERR : ce qu'un script y écrit s'affiche dans la console.
if (!defined('STDOUT')) {
    define('STDOUT', fopen('php://output', 'w'));
}
if (!defined('STDERR')) {
    define('STDERR', fopen('php://output', 'w'));
}

$arguments = json_decode($_SERVER['CONSOLE_ARGS'] ?? '[]', true) ?: [];
$script = array_shift($arguments);
if (null === $script) {
    echo 'Usage: php <script.php> [arguments]', MARKER, 1;

    return;
}
// « composer … » : le petit Composer de l'environnement (sans réseau), qui rend son code de sortie.
if ('composer' === $script && is_file('/app/.forelse/composer.php')) {
    $argv = ['composer', ...$arguments];
    $_SERVER['argv'] = $argv;
    $resultat = require '/app/.forelse/composer.php';
    echo MARKER, is_int($resultat) ? $resultat : 0;

    return;
}

$path = realpath($script);
if (false === $path || !is_file($path) || !str_starts_with($path, '/app/')) {
    echo 'Could not open input file: ', $script, MARKER, 1;

    return;
}

$argv = [$script, ...$arguments];
$argc = count($argv);
$_SERVER['argv'] = $argv;
$_SERVER['argc'] = $argc;

// exit() dans le script arrête tout : le marqueur part quand même, avec le code donné quand il est connu.
$termine = false;
register_shutdown_function(static function () use (&$termine): void {
    if (!$termine) {
        echo MARKER, 0;
    }
});

try {
    $resultat = require $path;
    $exitCode = is_int($resultat) && 1 !== $resultat ? $resultat : 0;
} catch (Throwable $e) {
    echo 'PHP Fatal error:  Uncaught ', get_class($e), ': ', $e->getMessage(), ' in ', $e->getFile(), ':', $e->getLine();
    $exitCode = 255;
}

$termine = true;
echo MARKER, $exitCode;
