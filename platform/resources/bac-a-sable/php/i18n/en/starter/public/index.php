<?php

require __DIR__.'/../vendor/autoload.php';

use App\Salutation;
use App\Vue;

// Edit this code: the preview updates as you type.
// The preview serves the project under a prefix: $base strips it from the requested path, and prefixes links.
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$chemin = substr((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), strlen($base)) ?: '/';

if ('/' === $chemin) {
    echo Vue::rendre('accueil', [
        'titre' => 'Hello PHP!',
        'base' => $base,
        'idees' => [
            'Add a page to the conditions above',
            'Create a class in src/: Composer loads it for you',
            'Run "php bin/bonjour.php Ada" in the console',
        ],
    ]);
} elseif (preg_match('#^/bonjour/([^/]+)$#', $chemin, $morceaux)) {
    // Try /bonjour/Ada in the preview's address bar.
    header('Content-Type: application/json');
    echo json_encode(['message' => (new Salutation())->pour(urldecode($morceaux[1]))], JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(404);
    echo Vue::rendre('introuvable', ['chemin' => $chemin, 'base' => $base]);
}
