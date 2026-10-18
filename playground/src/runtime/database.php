<?php

/*
 * L'onglet « Base » : les bases SQLite que l'application a créées dans le projet (var/, database/), avec leurs
 * tables et leurs premières lignes, en JSON. La base est lue telle quelle, sans passer par l'application : une
 * colonne chiffrée s'y montre chiffrée.
 *
 * Une valeur qui n'est pas du texte lisible (une colonne chiffrée, un BLOB) devient sa taille et le début de ses
 * octets en hexadécimal : sinon json_encode échouerait sur l'UTF-8 invalide, et la page afficherait du charabia.
 */

const LIGNES = 100;
const DEBUT = 24;

function valeur(mixed $valeur): mixed
{
    if (!\is_string($valeur) || (1 === preg_match('//u', $valeur) && 0 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $valeur))) {
        return $valeur;
    }

    return ['bytes' => \strlen($valeur), 'hex' => bin2hex(substr($valeur, 0, DEBUT))];
}

$bases = [];
foreach (['var', 'database'] as $dossier) {
    $chemin = '/app/'.$dossier;
    if (!is_dir($chemin)) {
        continue;
    }
    foreach (scandir($chemin) as $nom) {
        if (\in_array(pathinfo($nom, \PATHINFO_EXTENSION), ['db', 'sqlite', 'sqlite3'], true) && is_file("$chemin/$nom") && filesize("$chemin/$nom") > 0) {
            $bases[] = "$dossier/$nom";
        }
    }
}

$resultat = [];
foreach ($bases as $fichier) {
    try {
        $pdo = new PDO('sqlite:/app/'.$fichier, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $tables = [];
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $nom = '"'.str_replace('"', '""', $table).'"';
            $colonnes = array_column($pdo->query("PRAGMA table_info($nom)")->fetchAll(PDO::FETCH_ASSOC), 'name');
            $lignes = [];
            foreach ($pdo->query("SELECT * FROM $nom LIMIT ".LIGNES)->fetchAll(PDO::FETCH_NUM) as $ligne) {
                $lignes[] = array_map(valeur(...), $ligne);
            }
            $tables[] = ['name' => $table, 'columns' => $colonnes, 'rows' => $lignes, 'count' => (int) $pdo->query("SELECT COUNT(*) FROM $nom")->fetchColumn()];
        }
        $resultat[] = ['file' => $fichier, 'tables' => $tables];
    } catch (Throwable $erreur) {
        $resultat[] = ['file' => $fichier, 'tables' => [], 'error' => $erreur->getMessage()];
    }
}

echo json_encode($resultat, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PRESERVE_ZERO_FRACTION);
