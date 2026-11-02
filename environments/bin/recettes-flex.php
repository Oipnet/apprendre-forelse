<?php

/**
 * Les recettes Symfony Flex des paquets du catalogue (environments/bin/build-paquets.php), pour un environnement qui a
 * Flex : le navigateur n'a pas de réseau, il ne peut pas les lire lui-même. On les lit donc à l'empaquetage, aux mêmes
 * adresses que Flex (github.com/symfony/recipes, puis recipes-contrib), on choisit la même version que lui, et on n'en
 * garde que ce qui a un sens dans le bac à sable : les bundles de config/bundles.php, les fichiers copiés dans config/,
 * src/, templates/ et translations/, le bloc du .env et le message de fin d'installation. Le navigateur les applique
 * comme Flex (playground/src/app/recipes.ts) : un fichier qui existe déjà n'est pas remplacé.
 *
 * Restent de côté : Docker (docker-compose, Dockerfile), .gitignore, Makefile, les scripts Composer, et les lignes
 * qu'une recette ajoute à un fichier du projet (add-lines hors de ses propres fichiers, container:) — la recette les
 * nomme dans « ignore », pour qu'on sache ce que Flex aurait fait de plus.
 */

/** Les dépôts de recettes, dans l'ordre où Flex les consulte. */
const FLEX_DEPOTS = [
    'https://raw.githubusercontent.com/symfony/recipes/flex/main',
    'https://raw.githubusercontent.com/symfony/recipes-contrib/flex/main',
];
/** Les dossiers où une recette peut copier des fichiers (les autres n'existent pas dans le bac à sable). */
const FLEX_DOSSIERS = ['config/', 'src/', 'templates/', 'translations/'];
const FLEX_CHEMINS = ['%CONFIG_DIR%' => 'config', '%SRC_DIR%' => 'src', '%TEMPLATES_DIR%' => 'templates', '%TRANSLATIONS_DIR%' => 'translations', '%VAR_DIR%' => 'var', '%PUBLIC_DIR%' => 'public', '%ROOT_DIR%' => '.'];

final class RecettesFlex
{
    /** @var array<string, array<mixed>> l'index de chaque dépôt */
    private array $index = [];

    /**
     * @param array<string, array<mixed>> $installes les paquets installés (installed.json), par nom
     * @param \Closure(string): ?string    $lire      lit une adresse, null si elle n'existe pas
     */
    public function __construct(
        private readonly array $installes,
        private readonly string $php,
        private readonly \Closure $lire,
    ) {
    }

    /** Lit par HTTPS, en passant par le proxy de la machine s'il y en a un (comme Composer). */
    public static function lecteurHttp(): \Closure
    {
        $options = ['http' => ['timeout' => 30, 'ignore_errors' => true, 'header' => "User-Agent: forelse-build-paquets\r\n"]];
        $proxy = getenv('HTTPS_PROXY') ?: getenv('https_proxy') ?: null;
        if (null !== $proxy && false !== ($p = parse_url($proxy)) && isset($p['host'])) {
            $options['http']['proxy'] = 'tcp://'.$p['host'].':'.($p['port'] ?? 80);
            $options['http']['request_fulluri'] = false;
            if (isset($p['user'])) {
                $options['http']['header'] .= 'Proxy-Authorization: Basic '.base64_encode(urldecode($p['user']).':'.urldecode($p['pass'] ?? ''))."\r\n";
            }
        }

        return static function (string $url) use ($options): ?string {
            for ($essai = 1; $essai <= 3; ++$essai) {
                $contenu = @file_get_contents($url, false, stream_context_create($options));
                $statut = (int) (preg_match('{^HTTP/\S+ (\d+)}', $http_response_header[0] ?? '', $m) ? $m[1] : 0);
                if (false !== $contenu && 200 === $statut) {
                    return $contenu;
                }
                if (404 === $statut) {
                    return null;
                }
                sleep($essai);
            }
            throw new \RuntimeException("Recettes Flex : {$url} est injoignable.");
        };
    }

    /**
     * La recette d'un paquet, telle que le navigateur l'applique, ou null s'il n'en a pas (ni recette, ni bundle à
     * déclarer).
     *
     * @return ?array{paquet: string, origine: string, bundles: array<string, list<string>>, fichiers: array<string, string>, env: list<string>, message: string, ignore: list<string>}
     */
    public function recette(string $nom): ?array
    {
        $paquet = $this->installes[$nom] ?? null;
        if (null === $paquet) {
            return null;
        }
        foreach (FLEX_DEPOTS as $depot) {
            $index = $this->index[$depot] ??= json_decode((string) ($this->lire)($depot.'/index.json'), true) ?: [];
            $version = $this->version($nom, $index);
            if (null === $version) {
                continue;
            }
            $modele = $index['_links']['recipe_template'] ?? $depot.'/{package_dotted}.{version}.json';
            $url = strtr($modele, ['{package_dotted}' => str_replace('/', '.', $nom), '{version}' => $version]);
            $donnees = json_decode((string) ($this->lire)($url), true);
            $recette = $donnees['manifests'][$nom] ?? null;
            if (!is_array($recette)) {
                throw new \RuntimeException("Recette illisible : {$url}");
            }
            $origine = strtr($index['_links']['origin_template'] ?? '{package}:{version}', ['{package}' => $nom, '{version}' => $version]);

            return $this->traduire($nom, $origine, $recette['manifest'] ?? [], is_array($recette['files'] ?? null) ? $recette['files'] : []);
        }

        return $this->recetteAutomatique($nom, $paquet);
    }

    /**
     * La version de recette que Flex choisit : la plus haute qui ne dépasse pas celle du paquet, et dont les conflits
     * (« recipe-conflicts » de l'index) ne touchent aucun paquet installé.
     */
    private function version(string $nom, array $index): ?string
    {
        $versions = $index['recipes'][$nom] ?? null;
        if (!is_array($versions)) {
            return null;
        }
        usort($versions, static fn (string $a, string $b) => version_compare($b, $a));
        $installee = $this->normaliser($this->installes[$nom]);
        foreach ($versions as $version) {
            if (version_compare($version, $installee, '>')) {
                continue;
            }
            foreach ($index['recipe-conflicts'][$nom][$version] ?? [] as $autre => $contrainte) {
                if ($this->satisfait($autre, (string) $contrainte)) {
                    continue 2;
                }
            }

            return $version;
        }

        return null;
    }

    /** Le paquet (ou PHP) installé satisfait-il la contrainte (« <8.2 », « >=6.4,<7.0 », « ^7.0 || ^8.0 ») ? */
    public function satisfait(string $nom, string $contrainte): bool
    {
        $version = 'php' === $nom ? $this->php : (isset($this->installes[$nom]) ? $this->normaliser($this->installes[$nom]) : null);
        if (null === $version) {
            return false;
        }
        foreach (preg_split('/\s*\|\|?\s*/', trim($contrainte)) as $alternative) {
            $toutes = true;
            foreach (preg_split('/\s*,\s*|\s+/', $alternative) as $partie) {
                if (!$this->satisfaitUne($version, $partie)) {
                    $toutes = false;
                    break;
                }
            }
            if ($toutes) {
                return true;
            }
        }

        return false;
    }

    private function satisfaitUne(string $version, string $partie): bool
    {
        if ('*' === $partie || '' === $partie) {
            return true;
        }
        if (!preg_match('/^(<=|>=|<|>|==|=|!=|\^|~)?v?(\d+(?:\.\d+)*)(?:\.\*)?$/', $partie, $m)) {
            throw new \RuntimeException("Contrainte de recette non comprise : {$partie}");
        }
        [, $op, $borne] = $m;
        $morceaux = explode('.', $borne);

        return match ($op) {
            '^' => version_compare($version, $borne, '>=') && version_compare($version, ((int) $morceaux[0] + 1).'.0', '<'),
            '~' => version_compare($version, $borne, '>=') && version_compare($version, 1 === count($morceaux) ? ((int) $morceaux[0] + 1).'.0' : $morceaux[0].'.'.((int) $morceaux[1] + 1), '<'),
            '' , '=', '==' => str_ends_with($partie, '.*') ? str_starts_with($version.'.', $borne.'.') : version_compare($version, $borne, '=='),
            default => version_compare($version, $borne, $op),
        };
    }

    private function normaliser(array $paquet): string
    {
        return preg_replace('/^v/', '', (string) ($paquet['version_normalized'] ?? $paquet['version']));
    }

    /** Une recette de Flex, réduite à ce que le bac à sable applique. */
    private function traduire(string $nom, string $origine, array $manifeste, array $fichiers): array
    {
        $resultat = ['paquet' => $nom, 'origine' => $origine, 'bundles' => [], 'fichiers' => [], 'env' => [], 'message' => '', 'ignore' => []];
        foreach ($manifeste as $configurateur => $valeur) {
            switch ($configurateur) {
                case 'bundles':
                    foreach ($valeur as $classe => $environnements) {
                        $resultat['bundles'][ltrim($classe, '\\')] = array_values($environnements);
                    }
                    break;
                case 'copy-from-recipe':
                    foreach ($valeur as $source => $cible) {
                        $cible = rtrim(strtr($cible, FLEX_CHEMINS), '/');
                        foreach ($fichiers as $chemin => $fichier) {
                            if ($chemin !== $source && !(str_ends_with($source, '/') && str_starts_with($chemin, $source))) {
                                continue;
                            }
                            $destination = ltrim(preg_replace('{^\./}', '', $cible.substr($chemin, strlen(rtrim($source, '/')))), '/');
                            if (str_ends_with($destination, '/.gitignore') || str_ends_with($destination, '/.gitkeep')) {
                                continue;
                            }
                            $dansLeProjet = array_filter(FLEX_DOSSIERS, static fn (string $dossier) => str_starts_with($destination, $dossier));
                            if ([] === $dansLeProjet) {
                                $resultat['ignore'][] = $destination;
                                continue;
                            }
                            $contenu = $fichier['contents'] ?? '';
                            $resultat['fichiers'][$destination] = is_array($contenu) ? implode("\n", $contenu) : (string) $contenu;
                        }
                    }
                    break;
                case 'env':
                    foreach ($valeur as $cle => $val) {
                        $val = preg_replace_callback('/%generate\(secret(?:,\s*(\d+))?\)%/', static fn (array $m) => bin2hex(random_bytes((int) ($m[1] ?? 16))), (string) $val);
                        $resultat['env'][] = str_starts_with((string) $cle, '#') ? '# '.$val : $cle.'='.$val;
                    }
                    break;
                case 'add-lines':
                    foreach ($valeur as $ajout) {
                        $fichier = (string) ($ajout['file'] ?? '');
                        $fichier = strtr($fichier, FLEX_CHEMINS);
                        if (isset($ajout['requires']) && !$this->exige((string) $ajout['requires'])) {
                            continue;
                        }
                        if (!isset($resultat['fichiers'][$fichier])) {
                            $resultat['ignore'][] = $fichier;
                            continue;
                        }
                        $resultat['fichiers'][$fichier] = self::ajouterLignes($resultat['fichiers'][$fichier], $ajout);
                    }
                    break;
                case 'post-install-output':
                    $resultat['message'] = trim(preg_replace('{</?(?:info|comment|fg=[a-z]+|bg=[a-z]+|error|question)?>}', '', implode("\n", (array) $valeur)), "\n");
                    break;
                case 'aliases':
                case 'conflict':
                case 'gitignore':
                case 'composer-scripts':
                    break;
                default:
                    $resultat['ignore'][] = $configurateur;
            }
        }
        // Une ligne add-lines peut viser un fichier copié plus loin dans le manifeste : on repasse une fois.
        foreach ($manifeste['add-lines'] ?? [] as $ajout) {
            $fichier = strtr((string) ($ajout['file'] ?? ''), FLEX_CHEMINS);
            if (in_array($fichier, $resultat['ignore'], true) && isset($resultat['fichiers'][$fichier]) && (!isset($ajout['requires']) || $this->exige((string) $ajout['requires']))) {
                $resultat['fichiers'][$fichier] = self::ajouterLignes($resultat['fichiers'][$fichier], $ajout);
                $resultat['ignore'] = array_values(array_diff($resultat['ignore'], [$fichier]));
            }
        }
        $resultat['ignore'] = array_values(array_unique($resultat['ignore']));

        return $resultat;
    }

    /** « phpunit/phpunit:>=10 » : un paquet et sa contrainte, comme le « requires » d'un add-lines. */
    private function exige(string $exigence): bool
    {
        [$paquet, $contrainte] = explode(':', $exigence, 2) + [1 => '*'];

        return $this->satisfait($paquet, $contrainte);
    }

    /** Le configurateur add-lines de Flex, sur un fichier de la recette. */
    public static function ajouterLignes(string $contenu, array $ajout): string
    {
        $ligne = (string) $ajout['content'];
        if (str_contains($contenu, $ligne)) {
            return $contenu;
        }

        return match ($ajout['position'] ?? 'bottom') {
            'top' => $ligne."\n".$contenu,
            'after_target', 'before_target' => (static function () use ($contenu, $ligne, $ajout): string {
                $lignes = explode("\n", $contenu);
                foreach ($lignes as $i => $existante) {
                    if (str_contains($existante, (string) $ajout['target'])) {
                        array_splice($lignes, 'after_target' === $ajout['position'] ? $i + 1 : $i, 0, [$ligne]);

                        return implode("\n", $lignes);
                    }
                }

                return $contenu;
            })(),
            default => rtrim($contenu, "\n")."\n".$ligne."\n",
        };
    }

    /**
     * Sans recette, Flex déclare quand même le bundle d'un paquet « symfony-bundle » (recette « auto-generated ») : la
     * classe *Bundle.php à la racine de son espace de noms PSR-4.
     */
    private function recetteAutomatique(string $nom, array $paquet): ?array
    {
        if ('symfony-bundle' !== ($paquet['type'] ?? null)) {
            return null;
        }
        foreach ($paquet['autoload']['psr-4'] ?? [] as $prefixe => $dossiers) {
            foreach ((array) $dossiers as $dossier) {
                $racine = ($paquet['install-path-absolu'] ?? '').'/'.trim($dossier, '/');
                foreach (glob(rtrim($racine, '/').'/*Bundle.php') ?: [] as $fichier) {
                    return [
                        'paquet' => $nom,
                        'origine' => $nom.':auto-generated recipe',
                        'bundles' => [rtrim($prefixe, '\\').'\\'.basename($fichier, '.php') => ['all']],
                        'fichiers' => [],
                        'env' => [],
                        'message' => '',
                        'ignore' => [],
                    ];
                }
            }
        }

        return null;
    }
}
