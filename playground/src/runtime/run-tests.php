<?php

/**
 * Lance PHPUnit dans le projet /app et imprime un rapport JSON après le marqueur.
 * Le rapport est construit depuis le log JUnit (SimpleXML), plus fiable que la sortie texte.
 *
 * $_SERVER['RUNNER_PATHS'] (JSON) : fichiers de test à lancer, tous par défaut.
 */

const MARKER = "\n@@RAPPORT@@\n";
$junit = '/tmp/junit.xml';
@unlink($junit);

chdir('/app');
$paths = json_decode($_SERVER['RUNNER_PATHS'] ?? '[]', true) ?: [];
$argv = ['phpunit', '--colors=never', '--do-not-cache-result', '--log-junit', $junit, ...$paths];
$_SERVER['argv'] = $argv;
$_SERVER['argc'] = count($argv);

require '/app/vendor/autoload.php';

ob_start();
try {
    $exitCode = (new PHPUnit\TextUI\Application())->run($argv);
} catch (Throwable $e) {
    $exitCode = 2;
    echo $e;
}
$output = ob_get_clean();

$cases = [];
if (is_file($junit) && ($xml = simplexml_load_file($junit))) {
    foreach ($xml->xpath('//testcase') as $testcase) {
        $status = 'passed';
        $message = null;
        foreach (['failure' => 'failed', 'error' => 'error', 'skipped' => 'skipped'] as $tag => $tagStatus) {
            if (isset($testcase->{$tag})) {
                $status = $tagStatus;
                $message = trim((string) $testcase->{$tag});
            }
        }
        $cases[] = [
            'className' => (string) $testcase['class'],
            'name' => (string) $testcase['name'],
            // Relatif au projet : distingue les tests de l'apprenant des tests cachés.
            'file' => preg_replace('#^/app/#', '', (string) $testcase['file']),
            'status' => $status,
            'message' => $message,
            'timeMs' => round((float) $testcase['time'] * 1000, 1),
        ];
    }
}

echo MARKER.json_encode(['exitCode' => $exitCode, 'output' => $output, 'cases' => $cases], JSON_INVALID_UTF8_SUBSTITUTE);
