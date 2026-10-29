<?php

/**
 * Empaquette les paquets Composer qu'un environnement propose d'ajouter (bac à sable PHP, bouton « Paquets »).
 *
 * Usage : php environments/bin/build-paquets.php <environnement ou son dossier> [dossier de sortie]
 * (appelé par build-env.sh pour tout environnement qui a un paquets.json).
 *
 * Le catalogue est <environnement>/paquets.json. Tous les paquets se résolvent ensemble, dans un seul projet
 * Composer : deux paquets qui partagent une dépendance en ont donc la même version, et leurs archives peuvent se
 * superposer dans le projet de l'apprenant. Chaque paquet donne une archive avec ses dépendances, et sa table
 * d'autoload (vendor/forelse-paquets/<slug>.php) que lit le chargeur du projet (.forelse/paquets.php). Le navigateur
 * n'a jamais besoin du réseau : il télécharge ces archives depuis /envs, comme l'environnement lui-même.
 *
 * Sortie : <environnement>.paquets.json (le catalogue, avec version, poids et nom de chaque archive) et
 * <environnement>.paquet.<slug>.<empreinte>.zip.
 */

const MAX_FICHIER = 2_000_000;
/** Dossiers d'un paquet que l'apprenant n'exécute jamais (une archive Composer les exclut déjà, d'ordinaire). */
const HORS_ARCHIVE = ['.git', '.github', 'tests', 'Tests', 'test', 'docs', 'doc'];

[$script, $envId, $sortie] = $argv + [1 => null, 2 => null];
if (null === $envId) {
    fwrite(STDERR, "Usage : php {$script} <environnement> [dossier de sortie]\n");
    exit(1);
}
$racine = dirname(__DIR__);
$sortie ??= getenv('ENVIRONMENTS_OUT') ?: $racine.'/../platform/public/envs';
$envDir = is_dir($envId) ? rtrim($envId, '/') : $racine.'/'.$envId;
$envId = basename($envDir);
$catalogue = json_decode((string) @file_get_contents($envDir.'/paquets.json'), true);
if (!is_array($catalogue['paquets'] ?? null)) {
    fwrite(STDERR, "Pas de catalogue lisible : {$envDir}/paquets.json\n");
    exit(1);
}
if (!preg_match('/^php:\s*[\'"]?(\d+\.\d+)/m', (string) file_get_contents($envDir.'/environment.yaml'), $php)) {
    fwrite(STDERR, "Environnement {$envId} : version de PHP introuvable.\n");
    exit(1);
}

$travail = sys_get_temp_dir().'/paquets-'.$envId.'-'.bin2hex(random_bytes(4));
mkdir($travail, recursive: true);
register_shutdown_function(static fn () => exec('rm -rf '.escapeshellarg($travail)));

// La version de PHP du navigateur, pas celle de la machine qui empaquette.
file_put_contents($travail.'/composer.json', json_encode([
    'require' => array_column($catalogue['paquets'], 'version', 'nom'),
    'config' => ['platform' => ['php' => $php[1].'.0'], 'allow-plugins' => false, 'sort-packages' => true],
], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
passthru('cd '.escapeshellarg($travail).' && composer update --no-interaction --prefer-dist --no-scripts --no-plugins --no-progress --quiet && composer dump-autoload --optimize --no-scripts --quiet', $code);
if (0 !== $code) {
    fwrite(STDERR, "Composer a échoué pour le catalogue de {$envId}.\n");
    exit(1);
}

// Même garde que build-env.sh : quand GitHub refuse ses archives, Composer se rabat sur un clone git, et
// l'archive grossit de tout l'historique et des tests. PAQUETS_CLONES_ACCEPTES=1 passe outre (mesure en local).
exec('cd '.escapeshellarg($travail).' && find vendor -mindepth 3 -maxdepth 3 -name .git', $clones);
if ([] !== $clones && '1' !== getenv('PAQUETS_CLONES_ACCEPTES')) {
    fwrite(STDERR, "Ces paquets ont été installés par clone git, et non depuis leur archive :\n  - ".implode("\n  - ", array_map(static fn (string $c) => substr(dirname($c), 7), $clones))."\nDonnez un jeton GitHub à Composer (COMPOSER_AUTH), ou réessayez plus tard.\n");
    exit(1);
}

$installes = [];
foreach (json_decode((string) file_get_contents($travail.'/vendor/composer/installed.json'), true)['packages'] as $paquet) {
    $installes[$paquet['name']] = $paquet;
    foreach ([...array_keys($paquet['replace'] ?? []), ...array_keys($paquet['provide'] ?? [])] as $fourni) {
        $installes[$fourni] ??= $paquet;
    }
}
$vendor = $travail.'/vendor';
// Les tables de Composer, chemins rendus relatifs à vendor/.
$classes = array_filter(array_map(static fn (string $chemin) => str_starts_with($chemin, $vendor.'/') ? substr($chemin, strlen($vendor) + 1) : null, require $vendor.'/composer/autoload_classmap.php'));
$fichiers = array_filter(array_map(static fn (string $chemin) => str_starts_with($chemin, $vendor.'/') ? substr($chemin, strlen($vendor) + 1) : null, require $vendor.'/composer/autoload_files.php'));

/** Le paquet et ses dépendances installées, par nom. */
$fermeture = static function (string $nom) use ($installes): array {
    $vus = [];
    $file = [$nom];
    while (null !== ($courant = array_shift($file))) {
        $paquet = $installes[$courant] ?? null;
        if (null === $paquet || isset($vus[$paquet['name']])) {
            continue;
        }
        $vus[$paquet['name']] = $paquet;
        foreach (array_keys($paquet['require'] ?? []) as $dependance) {
            if (str_contains($dependance, '/')) {
                $file[] = $dependance;
            }
        }
    }
    ksort($vus);

    return $vus;
};

@mkdir($sortie, recursive: true);
foreach (glob($sortie.'/'.$envId.'.paquet.*.zip') ?: [] as $ancienne) {
    unlink($ancienne);
}
$manifeste = [];
foreach ($catalogue['paquets'] as $entree) {
    $nom = $entree['nom'];
    $paquets = $fermeture($nom);
    if (!isset($paquets[$nom])) {
        fwrite(STDERR, "{$nom} n'a pas été installé.\n");
        exit(1);
    }
    $slug = str_replace('/', '--', $nom);
    $dossiers = array_map(static fn (string $p) => $p.'/', array_keys($paquets));
    $dans = static function (string $chemin) use ($dossiers): bool {
        foreach ($dossiers as $dossier) {
            if (str_starts_with($chemin, $dossier)) {
                return true;
            }
        }

        return false;
    };

    $etape = $travail.'/archive-'.$slug;
    mkdir($etape.'/vendor/forelse-paquets', recursive: true);
    $liste = [];
    foreach (array_keys($paquets) as $p) {
        $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vendor.'/'.$p, FilesystemIterator::SKIP_DOTS));
        foreach ($iterateur as $fichier) {
            /** @var SplFileInfo $fichier */
            $dansLePaquet = substr($fichier->getPathname(), strlen($vendor.'/'.$p) + 1);
            if (!$fichier->isFile() || in_array(explode('/', $dansLePaquet)[0], HORS_ARCHIVE, true)) {
                continue;
            }
            $contenu = (string) file_get_contents($fichier->getPathname());
            // Le playground écrit des fichiers texte : un binaire (image, archive) n'y aurait pas de sens.
            if ($fichier->getSize() > MAX_FICHIER || str_contains($contenu, "\0") || !mb_check_encoding($contenu, 'UTF-8')) {
                continue;
            }
            $relatif = substr($fichier->getPathname(), strlen($vendor) + 1);
            @mkdir(dirname($etape.'/vendor/'.$relatif), recursive: true);
            copy($fichier->getPathname(), $etape.'/vendor/'.$relatif);
            $liste[] = 'vendor/'.$relatif;
        }
    }
    $table = [
        'classes' => array_filter($classes, $dans),
        'fichiers' => array_filter($fichiers, $dans),
    ];
    file_put_contents($etape.'/vendor/forelse-paquets/'.$slug.'.php', "<?php\n\n// {$nom} {$paquets[$nom]['version']} et ses dépendances : table d'autoload lue par .forelse/paquets.php.\nreturn ".var_export($table, true).";\n");
    $liste[] = 'vendor/forelse-paquets/'.$slug.'.php';

    $zip = $travail.'/'.$slug.'.zip';
    $proc = proc_open(['zip', '-qX9', $zip, '-@'], [0 => ['pipe', 'r']], $tuyaux, $etape);
    fwrite($tuyaux[0], implode("\n", $liste)."\n");
    fclose($tuyaux[0]);
    if (0 !== proc_close($proc)) {
        fwrite(STDERR, "zip a échoué pour {$nom}.\n");
        exit(1);
    }
    $empreinte = substr(hash_file('sha256', $zip), 0, 12);
    $archive = "{$envId}.paquet.{$slug}.{$empreinte}.zip";
    rename($zip, $sortie.'/'.$archive);

    $manifeste[] = [
        'nom' => $nom,
        'slug' => $slug,
        'version' => ltrim($paquets[$nom]['version'], 'v'),
        'description' => ['fr' => $entree['fr'], 'en' => $entree['en']],
        'dependances' => array_values(array_diff(array_keys($paquets), [$nom])),
        'archive' => $archive,
        'poids' => filesize($sortie.'/'.$archive),
    ];
    printf("%7s  %s %s (%d paquets)\n", number_format(filesize($sortie.'/'.$archive) / 1024, 0, ',', ' ').' Ko', $nom, $paquets[$nom]['version'], count($paquets));
}

file_put_contents($sortie.'/'.$envId.'.paquets.json', json_encode(['paquets' => $manifeste], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n");
echo "{$sortie}/{$envId}.paquets.json\n";
