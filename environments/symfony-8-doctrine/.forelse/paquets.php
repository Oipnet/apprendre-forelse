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
    $versions = [];
    foreach (glob($vendor.'/forelse-paquets/*.php') ?: [] as $table) {
        $paquet = require $table;
        $classes += $paquet['classes'];
        $fichiers += $paquet['fichiers'];
        $versions += $paquet['paquets'] ?? [];
    }
    if ([] === $classes && [] === $fichiers) {
        return;
    }
    // Composer\InstalledVersions doit connaître ces paquets : Symfony n'active une fonctionnalité (validation,
    // sérialiseur…) que si le paquet est installé à ses yeux (ContainerBuilder::willBeAvailable), et certains bundles
    // lisent leur version. reload() ajoute ce jeu de données à celui de vendor/composer/installed.php.
    if ([] !== $versions && class_exists(Composer\InstalledVersions::class)) {
        $installes = [];
        foreach ($versions as $nom => $version) {
            $normale = implode('.', array_pad(explode('.', ltrim((string) $version, 'v')), 4, '0'));
            $installes[$nom] = ['pretty_version' => $version, 'version' => $normale, 'reference' => null, 'type' => 'library', 'install_path' => $vendor.'/'.$nom, 'aliases' => [], 'dev_requirement' => false];
        }
        Composer\InstalledVersions::reload(['root' => Composer\InstalledVersions::getRootPackage(), 'versions' => $installes]);
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
