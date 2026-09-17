<?php

/**
 * Sert une requête de l'aperçu pour un environnement Docker : le navigateur de l'apprenant visite
 * http://localhost:PORT/… ; le simulateur trouve le conteneur qui publie ce port et le fait répondre.
 */

putenv('DOCKER_SIM_INPROCESS=1');
require '/app/vendor/autoload.php';

use Forelse\DockerSim\Engine\Docker;

$request = json_decode($_SERVER['DOCKER_HTTP'] ?? '{}', true) ?: [];
$port = (int) ($request['port'] ?? 80);
$uri = (string) ($request['uri'] ?? '/');
$base = (string) ($request['base'] ?? '');
$body = (string) file_get_contents('php://input');

$docker = new Docker('/tmp/docker-sim', '/app');
$response = $docker->http((string) ($request['method'] ?? 'GET'), 'localhost:'.$port.$uri, (array) ($request['headers'] ?? []), $body);
$docker->save();

if ($response->error !== null) {
    http_response_code(502);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Docker-Sim-Error: '.$response->error);
    echo Docker::browserError($response, $port);

    return;
}

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    if (strtolower($name) === 'location' && str_starts_with($value, '/') && !str_starts_with($value, '//')) {
        $value = '/localhost:'.$port.$value;
    }
    header($name.': '.$value);
}
header('X-Docker-Sim-Container: '.($response->container ?? ''));
$contentType = strtolower($response->headers['Content-Type'] ?? $response->headers['content-type'] ?? 'text/html');
$html = $response->body;
if (str_contains($contentType, 'text/html') && $base !== '') {
    // Les liens absolus (/menu) resteraient hors de l'aperçu : on les fait passer par le port visité.
    $prefix = $base.'/localhost:'.$port;
    $html = preg_replace('#\b(href|src|action)=(["\'])/(?!/)#i', '$1=$2'.$prefix.'/', $html) ?? $html;
}
echo $html;
