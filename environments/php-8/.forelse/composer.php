<?php

/**
 * Un petit Composer pour le projet sans framework : `composer dump-autoload`, `require`, `remove` et `install`, sans
 * réseau. Il tourne dans le PHP du navigateur (la console, voir playground/src/runtime/php-cli.php) comme dans le PHP
 * natif du vérificateur (App\Content\Check\PhpunitRunner), et écrit dans les deux cas le même vendor/autoload.php.
 *
 * - dump-autoload relit composer.json (psr-4, psr-0, classmap, files, et leurs pendants autoload-dev) et réécrit
 *   vendor/autoload.php. Les paquets de l'environnement gardent les tables que le vrai Composer a écrites dans
 *   vendor/composer/, que ce script ne touche jamais : on peut le relancer autant de fois qu'on veut.
 * - require et remove ne téléchargent rien : les paquets viennent du catalogue du bac à sable (paquets.json,
 *   environments/bin/build-paquets.php). Le navigateur dépose le paquet dans vendor/ avant d'appeler ce script ; le
 *   vérificateur donne le dossier des archives (variable FORELSE_PAQUETS, le catalogue construit) à `install`.
 *
 * Les messages imitent ceux de Composer, en anglais comme lui : l'apprenant retrouvera les mêmes dans son terminal.
 */

if (!class_exists('ForelseComposer', false)) {
    final class ForelseComposer
    {
        private const string AIDE = <<<'TEXTE'
            Composer (playground: no network, packages come from the catalog)

            Available commands:
              dump-autoload [-o]          Reads composer.json again and regenerates vendor/autoload.php
              require <vendor/package>    Adds a package of the catalog to composer.json and vendor/
              remove <vendor/package>     Removes a package from composer.json and vendor/
              install                     Installs the packages listed in composer.json
            TEXTE;

        /** @var list<string> */
        private array $sortie = [];
        private bool $silencieux = false;

        public function __construct(private readonly string $racine)
        {
        }

        /** @param list<string> $arguments les arguments après « composer » */
        public function run(array $arguments): int
        {
            $options = array_values(array_filter($arguments, static fn (string $a) => str_starts_with($a, '-')));
            $mots = array_values(array_filter($arguments, static fn (string $a) => !str_starts_with($a, '-')));
            $this->silencieux = [] !== array_intersect($options, ['-q', '--quiet']);
            $commande = array_shift($mots);
            try {
                return match ($commande) {
                    null, 'list', 'help' => $this->aide(),
                    'dump-autoload', 'dumpautoload', 'dump' => $this->dumpAutoload($this->optimise($options)),
                    'require', 'req', 'r' => $this->require($mots, \in_array('--dev', $options, true)),
                    'remove', 'rm', 'uninstall' => $this->remove($mots),
                    'install', 'i', 'update', 'u', 'upgrade' => $this->install($commande),
                    default => $this->erreur("Command \"{$commande}\" is not defined.\n\n".self::AIDE),
                };
            } catch (RuntimeException $e) {
                return $this->erreur($e->getMessage());
            }
        }

        /** Ce que la commande a affiché, une ligne par message. */
        public function sortie(): string
        {
            return [] === $this->sortie ? '' : implode("\n", $this->sortie)."\n";
        }

        private function aide(): int
        {
            $this->ecrire(self::AIDE);

            return 0;
        }

        /** @param list<string> $options */
        private function optimise(array $options): bool
        {
            return [] !== array_intersect($options, ['-o', '--optimize', '--optimize-autoloader', '-a', '--classmap-authoritative']);
        }

        private function dumpAutoload(bool $optimise): int
        {
            $composer = $this->lireComposerJson();
            $this->ecrire($optimise ? 'Generating optimized autoload files' : 'Generating autoload files');
            $classes = $this->ecrireAutoload($composer, $optimise);
            $this->ecrire($optimise ? "Generated optimized autoload files containing {$classes} classes" : 'Generated autoload files');

            return 0;
        }

        /** @param list<string> $demandes « vendeur/paquet », « vendeur/paquet:^1.3 » ou « vendeur/paquet ^1.3 » */
        private function require(array $demandes, bool $dev): int
        {
            $paquets = $this->demandes($demandes);
            if ([] === $paquets) {
                return $this->erreur("Not enough arguments (missing: \"packages\").\n\nUsage: composer require <vendor/package>");
            }
            $composer = $this->lireComposerJson();
            $installes = $this->installes();
            $section = $dev ? 'require-dev' : 'require';
            foreach ($paquets as $nom => $contrainte) {
                $version = $installes[$nom] ?? null;
                if (null === $version) {
                    throw new RuntimeException($this->introuvable($nom));
                }
                if (null === $contrainte) {
                    $contrainte = self::contrainte($version);
                    $this->ecrire("Using version {$contrainte} for {$nom}");
                }
                $composer[$section] = [...($composer[$section] ?? []), $nom => $contrainte];
                // Un paquet passé de require-dev à require (ou l'inverse) n'est pas déclaré deux fois.
                unset($composer[$dev ? 'require' : 'require-dev'][$nom]);
                if ([] === ($composer[$dev ? 'require' : 'require-dev'] ?? null)) {
                    unset($composer[$dev ? 'require' : 'require-dev']);
                }
            }
            if (true === ($composer['config']['sort-packages'] ?? false)) {
                $composer[$section] = self::trier($composer[$section]);
            }
            $this->ecrireComposerJson($composer);
            $this->ecrire('./composer.json has been updated');
            $noms = implode(' ', array_keys($paquets));
            $this->ecrire("Running composer update {$noms}");
            $this->ecrire('Loading composer repositories with package information');
            $this->ecrire('Updating dependencies');
            $nouveaux = $this->nouveauxDansLeLock($installes);
            $this->operations('Lock file operations', $nouveaux, 'Locking');
            $this->ecrireLock($composer, $installes);
            $this->ecrire('Writing lock file');
            $this->ecrire('Installing dependencies from lock file (including require-dev)');
            $this->operations('Package operations', $nouveaux, 'Installing', ': Extracting archive');
            $this->ecrire('Generating autoload files');
            $this->ecrireAutoload($composer, false);

            return 0;
        }

        /** @param list<string> $demandes */
        private function remove(array $demandes): int
        {
            $noms = array_keys($this->demandes($demandes));
            if ([] === $noms) {
                return $this->erreur("Not enough arguments (missing: \"packages\").\n\nUsage: composer remove <vendor/package>");
            }
            $composer = $this->lireComposerJson();
            $installes = $this->installes();
            $retires = [];
            foreach ($noms as $nom) {
                $trouve = false;
                foreach (['require', 'require-dev'] as $section) {
                    if (isset($composer[$section][$nom])) {
                        unset($composer[$section][$nom]);
                        $trouve = true;
                    }
                }
                if (!$trouve) {
                    $this->ecrire("{$nom} is not required in your composer.json and has not been removed");
                    continue;
                }
                $table = $this->racine.'/vendor/forelse-paquets/'.self::slug($nom).'.php';
                if (is_file($table)) {
                    unlink($table);
                }
                $retires[$nom] = $installes[$nom] ?? '';
            }
            if ([] === $retires) {
                return 1;
            }
            foreach (['require', 'require-dev'] as $section) {
                if ([] === ($composer[$section] ?? null) && 'require' !== $section) {
                    unset($composer[$section]);
                }
            }
            $this->ecrireComposerJson($composer);
            $this->ecrire('./composer.json has been updated');
            $this->ecrire('Running composer update '.implode(' ', array_keys($retires)));
            $this->ecrire('Loading composer repositories with package information');
            $this->ecrire('Updating dependencies');
            $this->operations('Lock file operations', $retires, 'Removing', '', 'removal');
            $this->ecrireLock($composer, $this->installes());
            $this->ecrire('Writing lock file');
            $this->operations('Package operations', $retires, 'Removing', '', 'removal');
            $this->ecrire('Generating autoload files');
            $this->ecrireAutoload($composer, false);

            return 0;
        }

        /** Les paquets de composer.json : ceux du catalogue qui manquent sont posés (vérificateur), puis l'autoload. */
        private function install(string $commande): int
        {
            $composer = $this->lireComposerJson();
            $installes = $this->installes();
            $manquants = [];
            foreach ([...($composer['require'] ?? []), ...($composer['require-dev'] ?? [])] as $nom => $contrainte) {
                if (str_contains((string) $nom, '/') && !isset($installes[$nom])) {
                    $manquants[] = (string) $nom;
                }
            }
            $poses = [];
            foreach ($manquants as $nom) {
                if (!$this->extraire($nom)) {
                    throw new RuntimeException($this->introuvable($nom));
                }
                $poses[$nom] = $this->installes()[$nom] ?? '';
            }
            $this->ecrire(\in_array($commande, ['install', 'i'], true) ? 'Installing dependencies from lock file (including require-dev)' : 'Loading composer repositories with package information');
            if ([] === $poses) {
                $this->ecrire('Nothing to install, update or remove');
            } else {
                $this->operations('Package operations', $poses, 'Installing', ': Extracting archive');
            }
            $this->ecrire('Generating autoload files');
            $this->ecrireAutoload($composer, false);

            return 0;
        }

        /**
         * Pose un paquet du catalogue depuis son archive, quand le dossier du catalogue est donné (FORELSE_PAQUETS :
         * vérificateur, content:check). PharData lit les archives zip sans l'extension zip.
         */
        private function extraire(string $nom): bool
        {
            $catalogue = getenv('FORELSE_PAQUETS');
            if (false === $catalogue || !is_file($catalogue) || !class_exists(PharData::class)) {
                return false;
            }
            foreach (json_decode((string) file_get_contents($catalogue), true)['paquets'] ?? [] as $entree) {
                if ($nom !== ($entree['nom'] ?? null) || !is_file($archive = \dirname($catalogue).'/'.basename((string) $entree['archive']))) {
                    continue;
                }
                (new PharData($archive))->extractTo($this->racine, null, true);

                return true;
            }

            return false;
        }

        private function introuvable(string $nom): string
        {
            $catalogue = getenv('FORELSE_PAQUETS');
            $disponibles = false !== $catalogue && is_file($catalogue)
                ? array_column(json_decode((string) file_get_contents($catalogue), true)['paquets'] ?? [], 'nom')
                : array_keys($this->installes());

            return "Could not find package {$nom}.\n\nThis playground has no network: only the packages of its catalog can be installed."
                .([] === $disponibles ? '' : "\nAvailable packages: ".implode(', ', $disponibles));
        }

        /**
         * Les paquets présents dans vendor/, avec leur version : ceux de l'environnement (installed.json, écrit par le
         * vrai Composer) et ceux du catalogue (une table par paquet dans vendor/forelse-paquets/).
         *
         * @return array<string, string>
         */
        private function installes(): array
        {
            $versions = [];
            $installed = json_decode((string) @file_get_contents($this->racine.'/vendor/composer/installed.json'), true);
            foreach ($installed['packages'] ?? (array_is_list($installed ?? []) ? $installed ?? [] : []) as $paquet) {
                $versions[$paquet['name']] = $paquet['version'] ?? '';
            }
            foreach ($this->tables() as $table) {
                $versions += $table['paquets'] ?? [];
                if (isset($table['nom'])) {
                    $versions[$table['nom']] ??= $table['version'] ?? '';
                }
            }

            return $versions;
        }

        /** @return list<array<string, mixed>> */
        private function tables(): array
        {
            $tables = [];
            foreach (glob($this->racine.'/vendor/forelse-paquets/*.php') ?: [] as $fichier) {
                $table = require $fichier;
                if (\is_array($table)) {
                    // Une table d'avant les noms (build-paquets.php) : le nom se lit dans celui du fichier.
                    $table['nom'] ??= str_replace('--', '/', basename($fichier, '.php'));
                    $tables[] = $table;
                }
            }

            return $tables;
        }

        /**
         * @param array<string, string> $avant
         *
         * @return array<string, string> les paquets que le composer.lock ne connaît pas encore
         */
        private function nouveauxDansLeLock(array $avant): array
        {
            $lock = json_decode((string) @file_get_contents($this->racine.'/composer.lock'), true);
            $verrouilles = array_column([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])], 'version', 'name');

            return array_diff_key($avant, $verrouilles, $this->paquetsDeLEnvironnement());
        }

        /** @return array<string, string> les paquets du vendor/ de l'environnement, qui ne s'installent jamais ici */
        private function paquetsDeLEnvironnement(): array
        {
            $installed = json_decode((string) @file_get_contents($this->racine.'/vendor/composer/installed.json'), true);

            return array_column($installed['packages'] ?? [], 'version', 'name');
        }

        /** @param array<string, string> $paquets */
        private function operations(string $titre, array $paquets, string $verbe, string $suite = '', string $sorte = 'install'): void
        {
            if ([] === $paquets) {
                $this->ecrire('Nothing to modify in lock file');

                return;
            }
            $n = \count($paquets);
            $this->ecrire('install' === $sorte ? "{$titre}: {$n} install".(1 === $n ? '' : 's').', 0 updates, 0 removals' : "{$titre}: 0 installs, 0 updates, {$n} removal".(1 === $n ? '' : 's'));
            foreach ($paquets as $nom => $version) {
                $this->ecrire("  - {$verbe} {$nom}".('' === $version ? '' : " ({$version})").$suite);
            }
        }

        /**
         * @param list<string> $mots
         *
         * @return array<string, ?string> nom => contrainte (null : celle de la version installée)
         */
        private function demandes(array $mots): array
        {
            $paquets = [];
            $dernier = null;
            foreach ($mots as $mot) {
                if (null !== $dernier && !str_contains($mot, '/')) {
                    $paquets[$dernier] = $mot; // « composer require nikic/fast-route ^1.3 »
                    $dernier = null;
                    continue;
                }
                [$nom, $contrainte] = array_pad(preg_split('/[:=]/', $mot, 2), 2, null);
                $nom = strtolower($nom);
                $paquets[$nom] = $contrainte;
                $dernier = null === $contrainte ? $nom : null;
            }

            return $paquets;
        }

        /** « v1.3.0 » donne « ^1.3 », comme Composer quand on ne précise pas de version. */
        private static function contrainte(string $version): string
        {
            if (!preg_match('/^v?(\d+)\.(\d+)(?:\.(\d+))?/', $version, $m)) {
                return '*';
            }

            return '0' === $m[1] ? "^0.{$m[2]}".(isset($m[3]) ? ".{$m[3]}" : '') : "^{$m[1]}.{$m[2]}";
        }

        /** @param array<string, string> $paquets Composer range d'abord php et les extensions, puis les paquets. */
        private static function trier(array $paquets): array
        {
            uksort($paquets, static function (string $a, string $b): int {
                $rang = static fn (string $n) => 'php' === $n ? 0 : (str_starts_with($n, 'ext-') ? 1 : (str_contains($n, '/') ? 3 : 2));

                return [$rang($a), $a] <=> [$rang($b), $b];
            });

            return $paquets;
        }

        private static function slug(string $nom): string
        {
            return str_replace('/', '--', $nom);
        }

        /** @return array<string, mixed> */
        private function lireComposerJson(): array
        {
            $chemin = $this->racine.'/composer.json';
            if (!is_file($chemin)) {
                throw new RuntimeException("Composer could not find a composer.json file in {$this->racine}");
            }
            $texte = (string) file_get_contents($chemin);
            $composer = json_decode($texte, true);
            if (!\is_array($composer)) {
                throw new RuntimeException("\"./composer.json\" does not contain valid JSON\n".self::erreurJson($texte));
            }

            return $composer;
        }

        /** Ce qui cloche dans un JSON, avec sa ligne quand on la trouve : la virgule de trop est l'erreur la plus courante. */
        private static function erreurJson(string $texte): string
        {
            json_decode($texte);
            $message = json_last_error_msg();
            if (preg_match('/,(\s*)[}\]]/', $texte, $m, \PREG_OFFSET_CAPTURE)) {
                $ligne = substr_count(substr($texte, 0, $m[0][1]), "\n") + 1;

                return "Parse error on line {$ligne}: trailing comma (a comma before } or ] is not allowed in JSON)";
            }

            return "Parse error: {$message}";
        }

        /** @param array<string, mixed> $composer */
        private function ecrireComposerJson(array $composer): void
        {
            foreach (['require', 'require-dev'] as $section) {
                if (isset($composer[$section]) && [] === $composer[$section]) {
                    $composer[$section] = new stdClass();
                }
            }
            file_put_contents($this->racine.'/composer.json', json_encode($composer, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n");
        }

        /**
         * Le composer.lock : ce qui est installé, pour qu'on voie le fichier apparaître et ce qu'il fige. Il ne sert
         * à rien d'autre ici (install relit composer.json).
         *
         * @param array<string, mixed>  $composer
         * @param array<string, string> $installes
         */
        private function ecrireLock(array $composer, array $installes): void
        {
            $dev = array_keys($composer['require-dev'] ?? []);
            $paquets = [];
            $paquetsDev = [];
            foreach ($installes as $nom => $version) {
                $entree = ['name' => $nom, 'version' => $version, 'dist' => ['type' => 'zip']];
                if (\in_array($nom, $dev, true)) {
                    $paquetsDev[] = $entree;
                } else {
                    $paquets[] = $entree;
                }
            }
            $empreinte = array_intersect_key($composer, array_flip(['name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra']));
            ksort($empreinte);
            $lock = [
                '_readme' => [
                    'This file locks the dependencies of your project to a known state',
                    'Read more about it at https://getcomposer.org/doc/01-basic-usage.md#installing-dependencies',
                    'This file is @generated automatically',
                ],
                'content-hash' => md5((string) json_encode($empreinte)),
                'packages' => $paquets,
                'packages-dev' => $paquetsDev,
                'aliases' => [],
                'minimum-stability' => $composer['minimum-stability'] ?? 'stable',
                'stability-flags' => new stdClass(),
                'prefer-stable' => $composer['prefer-stable'] ?? false,
                'prefer-lowest' => false,
                'platform' => (object) array_filter($composer['require'] ?? [], static fn ($n) => 'php' === $n || str_starts_with((string) $n, 'ext-'), \ARRAY_FILTER_USE_KEY),
                'platform-dev' => new stdClass(),
                'plugin-api-version' => '2.6.0',
            ];
            file_put_contents($this->racine.'/composer.lock', json_encode($lock, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n");
        }

        /**
         * Réécrit vendor/autoload.php : les règles du projet (composer.json) figées à cet instant, comme le fait
         * Composer, et celles des paquets reprises des tables de l'environnement et du catalogue.
         *
         * @param array<string, mixed> $composer
         *
         * @return int le nombre de classes de la table de classes
         */
        private function ecrireAutoload(array $composer, bool $optimise): int
        {
            $vendor = $this->racine.'/vendor';
            if (!is_file($vendor.'/composer/ClassLoader.php')) {
                throw new RuntimeException('vendor/composer/ClassLoader.php is missing: this project was not installed by Composer.');
            }
            $psr4 = [];
            $psr0 = [];
            $classes = [];
            $fichiers = [];

            // Les paquets de l'environnement : les tables du vrai Composer, sans les règles du projet qu'elles figeaient.
            $dansVendor = static fn (string $chemin) => str_starts_with($chemin, $vendor.'/');
            $relatif = fn (string $chemin) => substr($chemin, \strlen($this->racine) + 1);
            foreach (['autoload_psr4.php' => &$psr4, 'autoload_namespaces.php' => &$psr0] as $table => &$regles) {
                foreach (is_file($vendor.'/composer/'.$table) ? require $vendor.'/composer/'.$table : [] as $prefixe => $dossiers) {
                    $dossiers = array_values(array_map($relatif, array_filter((array) $dossiers, $dansVendor)));
                    if ([] !== $dossiers) {
                        $regles[$prefixe] = $dossiers;
                    }
                }
            }
            unset($regles);
            foreach (is_file($vendor.'/composer/autoload_classmap.php') ? require $vendor.'/composer/autoload_classmap.php' : [] as $classe => $chemin) {
                if ($dansVendor($chemin)) {
                    $classes[$classe] = $relatif($chemin);
                }
            }
            foreach (is_file($vendor.'/composer/autoload_files.php') ? require $vendor.'/composer/autoload_files.php' : [] as $chemin) {
                if ($dansVendor($chemin)) {
                    $fichiers[] = $relatif($chemin);
                }
            }

            // Le projet : autoload puis autoload-dev, comme un dump-autoload sans --no-dev.
            $avertissements = [];
            $classesDuProjet = [];
            foreach (['autoload', 'autoload-dev'] as $section) {
                $regles = $composer[$section] ?? [];
                if (!\is_array($regles)) {
                    continue;
                }
                foreach ((array) ($regles['psr-4'] ?? []) as $prefixe => $dossiers) {
                    $prefixe = (string) $prefixe;
                    if ('' !== $prefixe && !str_ends_with($prefixe, '\\')) {
                        throw new RuntimeException("A non-empty PSR-4 prefix must end with a namespace separator.\nIn composer.json, write \"".str_replace('\\', '\\\\', $prefixe).'\\\\" instead of "'.str_replace('\\', '\\\\', $prefixe).'".');
                    }
                    $dossiers = array_map(self::dossier(...), (array) $dossiers);
                    $psr4[$prefixe] = [...$dossiers, ...($psr4[$prefixe] ?? [])];
                    if ($optimise) {
                        foreach ($dossiers as $dossier) {
                            $classesDuProjet += $this->classesPsr4($prefixe, $dossier, $avertissements);
                        }
                    }
                }
                foreach ((array) ($regles['psr-0'] ?? []) as $prefixe => $dossiers) {
                    $psr0[(string) $prefixe] = [...array_map(self::dossier(...), (array) $dossiers), ...($psr0[(string) $prefixe] ?? [])];
                }
                foreach ((array) ($regles['classmap'] ?? []) as $chemin) {
                    $classesDuProjet += $this->classesDe(self::dossier((string) $chemin));
                }
                foreach ((array) ($regles['files'] ?? []) as $chemin) {
                    // Le chargeur des paquets du bac à sable : vendor/autoload.php lit déjà leurs tables.
                    if ('.forelse/paquets.php' !== ltrim((string) $chemin, './')) {
                        $fichiers[] = self::dossier((string) $chemin);
                    }
                }
            }
            foreach ($avertissements as $avertissement) {
                $this->ecrire($avertissement);
            }
            $classes = [...$classesDuProjet, ...$classes];
            ksort($classes);
            krsort($psr4);
            krsort($psr0);
            $autoritaire = $optimise && true === ($composer['config']['classmap-authoritative'] ?? false);

            $code = "<?php\n\n"
                ."// @generated par composer dump-autoload (voir .forelse/composer.php) : relancez-le après avoir changé l'autoload de composer.json.\n\n"
                ."require_once __DIR__.'/composer/ClassLoader.php';\n\n"
                // Une fois par processus, comme le vrai Composer : un second chargeur, enregistré par un second require
                // (les tests puis public/index.php), inclurait deux fois un fichier qui ne déclare pas la classe attendue.
                ."if (isset(\\Composer\\Autoload\\ClassLoader::getRegisteredLoaders()[__DIR__])) {\n"
                ."    return \\Composer\\Autoload\\ClassLoader::getRegisteredLoaders()[__DIR__];\n"
                ."}\n\n"
                ."return (static function (): \\Composer\\Autoload\\ClassLoader {\n"
                ."    \$racine = dirname(__DIR__);\n"
                .'    $chemins = static fn (array $relatifs) => array_map(static fn (string $c) => $racine.\'/\'.$c, $relatifs);'."\n"
                ."    \$loader = new \\Composer\\Autoload\\ClassLoader(__DIR__);\n"
                .'    foreach ('.var_export($psr4, true).' as $prefixe => $dossiers) {'."\n"
                ."        \$loader->setPsr4(\$prefixe, \$chemins(\$dossiers));\n"
                ."    }\n"
                .'    foreach ('.var_export($psr0, true).' as $prefixe => $dossiers) {'."\n"
                ."        \$loader->set(\$prefixe, \$chemins(\$dossiers));\n"
                ."    }\n"
                .'    $loader->addClassMap(array_map(static fn (string $c) => $racine.\'/\'.$c, '.var_export($classes, true).'));'."\n"
                ."    // Les paquets ajoutés depuis le catalogue (composer require, bouton « Paquets ») : leurs tables, lues à chaque fois.\n"
                ."    \$fichiersDesPaquets = [];\n"
                ."    foreach (glob(__DIR__.'/forelse-paquets/*.php') ?: [] as \$table) {\n"
                ."        \$paquet = require \$table;\n"
                ."        \$loader->addClassMap(array_map(static fn (string \$c) => __DIR__.'/'.\$c, \$paquet['classes'] ?? []));\n"
                ."        \$fichiersDesPaquets = [...\$fichiersDesPaquets, ...array_values(\$paquet['fichiers'] ?? [])];\n"
                ."    }\n"
                .($autoritaire ? "    \$loader->setClassMapAuthoritative(true);\n" : '')
                ."    \$loader->register(true);\n"
                ."    foreach (array_map(static fn (string \$c) => __DIR__.'/'.\$c, \$fichiersDesPaquets) as \$fichier) {\n"
                ."        require_once \$fichier;\n"
                ."    }\n"
                .'    foreach ($chemins('.var_export($fichiers, true).') as $fichier) {'."\n"
                ."        require_once \$fichier;\n"
                ."    }\n\n"
                ."    return \$loader;\n"
                ."})();\n";
            file_put_contents($vendor.'/autoload.php', $code);

            return \count($classes);
        }

        /** « ./src/ » devient « src » : un chemin relatif à la racine du projet, sans barre finale. */
        private static function dossier(string $chemin): string
        {
            $chemin = trim(preg_replace('#^(\./)+#', '', str_replace('\\', '/', $chemin)), '/');

            return '' === $chemin ? '.' : $chemin;
        }

        /**
         * Les classes d'un dossier PSR-4, pour la table de classes de dump-autoload -o ; celles qui ne suivent pas la
         * règle sont écartées avec le même avertissement que Composer.
         *
         * @param list<string> $avertissements
         *
         * @return array<string, string>
         */
        private function classesPsr4(string $prefixe, string $dossier, array &$avertissements): array
        {
            $retenues = [];
            foreach ($this->classesDe($dossier) as $classe => $chemin) {
                $attendu = $prefixe.str_replace('/', '\\', substr($chemin, \strlen(rtrim($dossier, '/')) + 1, -4));
                if ('.' === $dossier) {
                    $attendu = $prefixe.str_replace('/', '\\', substr($chemin, 0, -4));
                }
                if ($classe === $attendu) {
                    $retenues[$classe] = $chemin;
                } else {
                    $avertissements[] = "Class {$classe} located in ./{$chemin} does not comply with psr-4 autoloading standard (rule: {$prefixe} => ./{$dossier}). Skipping.";
                }
            }

            return $retenues;
        }

        /**
         * Les classes, interfaces, traits et enums déclarés dans un fichier ou un dossier (fichiers .php), avec leur
         * chemin relatif à la racine. Même méthode que Composer : le code sans commentaires, et une expression régulière.
         *
         * @return array<string, string>
         */
        private function classesDe(string $chemin): array
        {
            $absolu = $this->racine.'/'.$chemin;
            if (is_file($absolu)) {
                $fichiers = [$absolu];
            } elseif (is_dir($absolu)) {
                $fichiers = [];
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolu, FilesystemIterator::SKIP_DOTS)) as $fichier) {
                    /** @var SplFileInfo $fichier */
                    if ($fichier->isFile() && 'php' === $fichier->getExtension() && !str_contains($fichier->getPathname(), '/vendor/')) {
                        $fichiers[] = $fichier->getPathname();
                    }
                }
                sort($fichiers);
            } else {
                return [];
            }
            $classes = [];
            foreach ($fichiers as $fichier) {
                foreach (self::declarations(php_strip_whitespace($fichier)) as $classe) {
                    $classes[$classe] ??= substr($fichier, \strlen($this->racine) + 1);
                }
            }

            return $classes;
        }

        /** @return list<string> */
        private static function declarations(string $code): array
        {
            // Les chaînes ne déclarent rien : on les vide avant de chercher.
            $code = preg_replace('{"[^"\\\\]*+(?:\\\\.[^"\\\\]*+)*+"|\'[^\'\\\\]*+(?:\\\\.[^\'\\\\]*+)*+\'}s', '""', $code) ?? $code;
            preg_match_all('{\b(?<![\$:>])(?:(?P<type>class|interface|trait|enum)\s++(?P<nom>[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+)|(?P<ns>namespace)(?P<espace>\s++[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+(?:\s*+\\\\\s*+[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+)*+)?\s*+[\{;])}ix', $code, $trouves, \PREG_SET_ORDER);
            $espace = '';
            $classes = [];
            foreach ($trouves as $trouve) {
                if ('' !== ($trouve['ns'] ?? '')) {
                    $espace = str_replace([' ', "\t", "\r", "\n"], '', $trouve['espace'] ?? '');
                    $espace = '' === $espace ? '' : $espace.'\\';
                } elseif (!\in_array(strtolower($trouve['nom']), ['extends', 'implements', 'readonly'], true)) {
                    $classes[] = ltrim($espace.$trouve['nom'], '\\');
                }
            }

            return $classes;
        }

        private function ecrire(string $ligne): void
        {
            if (!$this->silencieux) {
                $this->sortie[] = $ligne;
            }
        }

        private function erreur(string $message): int
        {
            $this->sortie[] = "\n  ".str_replace("\n", "\n  ", $message)."\n";

            return 1;
        }
    }
}

// « php .forelse/composer.php install » (vérificateur) : la ligne de commande. Dans le navigateur, php-cli.php inclut
// ce fichier pour « composer … » et reçoit le code de sortie en retour.
$forelseComposer = new ForelseComposer(dirname(__DIR__));
$forelseCode = $forelseComposer->run(array_slice($argv ?? $_SERVER['argv'] ?? [], 1));
echo $forelseComposer->sortie();
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit($forelseCode);
}

return $forelseCode;
