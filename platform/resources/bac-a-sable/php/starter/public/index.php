<?php

require __DIR__.'/../vendor/autoload.php';

use App\Salutation;
use App\Vue;

// Modifiez ce code : l'aperçu se met à jour à chaque frappe.
// L'aperçu sert le projet sous un préfixe : $base le retire du chemin demandé, et préfixe les liens.
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$chemin = substr((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), strlen($base)) ?: '/';

if ('/' === $chemin) {
    echo Vue::rendre('accueil', [
        'titre' => 'Bonjour PHP !',
        'base' => $base,
        'idees' => [
            'Ajouter une page dans les conditions ci-dessus',
            'Créer une classe dans src/ : Composer la charge tout seul',
            'Lancer « php bin/bonjour.php Ada » dans la console',
        ],
    ]);
} elseif (preg_match('#^/bonjour/([^/]+)$#', $chemin, $morceaux)) {
    // Essayez /bonjour/Ada dans la barre d'adresse de l'aperçu.
    header('Content-Type: application/json');
    echo json_encode(['message' => (new Salutation())->pour(urldecode($morceaux[1]))], JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(404);
    echo Vue::rendre('introuvable', ['chemin' => $chemin, 'base' => $base]);
}
