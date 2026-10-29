<?php

/**
 * Les paquets ajoutés au bac à sable (bouton « Paquets ») : chacun dépose ses fichiers dans vendor/ et sa table
 * d'autoload dans vendor/forelse-paquets/ (voir environments/bin/build-paquets.php). Chargé par l'autoload de
 * Composer (« files » dans composer.json) : `require 'vendor/autoload.php'` suffit, comme après un composer require.
 */

(static function (): void {
    $vendor = dirname(__DIR__).'/vendor';
    $classes = [];
    $fichiers = [];
    foreach (glob($vendor.'/forelse-paquets/*.php') ?: [] as $table) {
        $paquet = require $table;
        $classes += $paquet['classes'];
        $fichiers += $paquet['fichiers'];
    }
    if ([] === $classes && [] === $fichiers) {
        return;
    }
    spl_autoload_register(static function (string $classe) use ($classes, $vendor): void {
        if (isset($classes[$classe])) {
            require $vendor.'/'.$classes[$classe];
        }
    });
    foreach ($fichiers as $fichier) {
        require_once $vendor.'/'.$fichier;
    }
})();
