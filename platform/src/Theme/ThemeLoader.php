<?php

namespace App\Theme;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Lit theme.yaml et le vérifie en entier, en une fois : une erreur (une couleur mal écrite, une clé inconnue,
 * une section d'accueil incomplète) est signalée avec le chemin de sa clé dès le chargement, et non au rendu
 * de la page qui s'en sert. Sans theme.yaml, c'est celui du moteur (theme.yaml, à côté de cette classe).
 *
 * Un dossier qui n'a que l'ancien marque.yaml est encore lu, au même format, avec un avis de dépréciation :
 * les instances d'avant le renommage gardent leur habillage jusqu'à la 4.0. Quand les deux existent, theme.yaml
 * l'emporte.
 *
 * Appelé par Theme au premier usage, par ThemeWarmer au démarrage, et par app:theme:verifier.
 */
final readonly class ThemeLoader
{
    public const string FILE = 'theme.yaml';

    /** @deprecated depuis 2.6, retiré en 4.0 : l'ancien nom de theme.yaml. */
    public const string LEGACY_FILE = 'marque.yaml';

    /** Les clés acceptées à la racine de theme.yaml. */
    public const array KEYS = ['name', 'chip', 'title', 'tagline', 'url', 'person', 'colors', 'editor', 'fonts', 'logo', 'email_logo', 'icon', 'share', 'home', 'stylesheets', 'scripts', 'preload', 'replaces_engine_styles'];

    /** Le sous-dossier du thème servi tel quel sur /theme/<thème>/<version>/assets/… (voir ThemeController). */
    public const string ASSETS = 'assets';

    /**
     * Ce que ce sous-dossier peut servir, et sous quel type : ni PHP, ni YAML, ni rien que le navigateur
     * exécuterait sans qu'on l'ait prévu. Une feuille de style y trouve ses polices et ses images.
     */
    public const array ASSET_TYPES = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'woff2' => 'font/woff2',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'jpg' => 'image/jpeg',
    ];

    /** Les images que l'instance peut fournir, et la route qui les sert (voir ThemeController). */
    public const array IMAGES = ['logo', 'email_logo', 'icon', 'share'];

    /**
     * La forme attendue de chaque section de « home: » : clé => type, un « * » marquant l'obligatoire.
     * « texte » pour une chaîne, « textes » pour une liste de chaînes, un tableau pour une sous-section.
     *
     * Vérifier cette forme n'est pas du zèle : en YAML, « - Un titre : une suite » est un tableau, pas une
     * phrase, et sans contrôle le gabarit afficherait « Array to string conversion » à la place de la page.
     */
    private const array HOME_SHAPE = [
        'showcase' => [
            'eyebrow' => 'texte',
            'title' => 'texte*',
            'lead' => 'textes',
            'sign' => ['name' => 'texte*', 'note' => 'texte', 'alt' => 'texte'],
        ],
        'author' => ['eyebrow' => 'texte', 'title' => 'texte*', 'lead' => 'texte*'],
        'demo' => [
            'files' => 'textes',
            'code' => 'texte',
            'preview' => ['url' => 'texte', 'name' => 'texte', 'label' => 'texte', 'value' => 'texte', 'row' => 'textes'],
            'goals' => 'textes',
        ],
    ];

    /** La personne derrière les contenus, pour les données structurées (auteur des parcours et des articles). */
    private const array PERSON_SHAPE = ['name' => 'texte*', 'jobTitle' => 'texte*'];

    public function __construct(
        /** Le thème du moteur : son accueil, sa Person. */
        private string $engineDirectory = __DIR__,
    ) {
    }

    /**
     * @throws \RuntimeException theme.yaml illisible ou invalide, avec le chemin de la clé en cause
     */
    public function load(string $directory): ThemeConfig
    {
        if ('' !== $directory) {
            if (is_file($directory.'/'.self::FILE)) {
                return $this->read($directory, self::FILE, false);
            }
            if (is_file($directory.'/'.self::LEGACY_FILE)) {
                trigger_deprecation('forelse/moteur', '2.6', 'Le thème de %s est décrit par « %s » : renommez ce fichier en « %s ». L\'ancien nom ne sera plus lu en 4.0.', $directory, self::LEGACY_FILE, self::FILE);

                return $this->read($directory, self::LEGACY_FILE, false);
            }
        }

        return $this->read($this->engineDirectory, self::FILE, true);
    }

    private function read(string $directory, string $file, bool $engine): ThemeConfig
    {
        $path = $directory.'/'.$file;
        try {
            $config = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new \RuntimeException(sprintf('Thème : %s est illisible — %s', $path, $e->getMessage()), previous: $e);
        }
        if (!\is_array($config)) {
            throw new \RuntimeException(sprintf('Thème : %s doit décrire le thème (voir la documentation).', $path));
        }
        $error = static fn (string $message) => new \RuntimeException(sprintf('Thème (%s) : %s', $path, $message));

        $keys = self::KEYS;
        foreach (array_keys($config) as $key) {
            if (!\in_array($key, $keys, true)) {
                throw $error(sprintf('clé « %s » inconnue (acceptées : %s).', $key, implode(', ', $keys)));
            }
        }
        if (!\is_string($config['name'] ?? null) || '' === trim($config['name'])) {
            throw $error('« name » est obligatoire : c\'est le nom affiché partout.');
        }
        $name = self::text($config, 'name', '', $error);

        return new ThemeConfig(
            isDefault: $engine,
            directory: $directory,
            file: $file,
            name: $name,
            chip: self::text($config, 'chip', 'apprendre', $error),
            // Le titre non déclaré retombe sur le nom, jamais sur celui du moteur.
            title: self::text($config, 'title', $name, $error),
            tagline: self::text($config, 'tagline', 'Apprendre à développer en codant dans le navigateur, sans vidéo ni installation.', $error),
            url: self::text($config, 'url', '', $error),
            images: $engine ? array_fill_keys(self::IMAGES, null) : self::images($config, $directory, $error),
            colors: self::block($config, 'colors', ThemeStylesheet::COLORS, new HexColor(), $error),
            editor: self::block($config, 'editor', ThemeStylesheet::EDITOR_COLORS, new HexColor(), $error),
            fonts: self::block($config, 'fonts', ThemeStylesheet::FONTS, new FontStack(), $error),
            home: self::home($config['home'] ?? [], $error),
            person: isset($config['person']) ? self::person($config['person'], $error) : null,
            stylesheets: self::assets($config, 'stylesheets', 'css', $directory, $error),
            scripts: self::assets($config, 'scripts', 'js', $directory, $error),
            preload: self::assets($config, 'preload', 'woff2', $directory, $error),
            replacesEngineStyles: self::replacesEngineStyles($config, $error),
        );
    }

    /**
     * Le fichier d'un thème que le navigateur peut demander : sous assets/, d'un type servi, et réellement dans le
     * dossier (un lien symbolique qui en sort est refusé). Null sinon, sans dire pourquoi : c'est une URL, pas un
     * fichier écrit par l'instance.
     */
    public static function assetPath(string $directory, string $path): ?string
    {
        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
        if ('' === $directory || !isset(self::ASSET_TYPES[$extension]) || !self::isPlainPath($path)) {
            return null;
        }
        $root = realpath($directory.'/'.self::ASSETS);
        $file = realpath($directory.'/'.self::ASSETS.'/'.$path);
        if (false === $root || false === $file || !str_starts_with($file, $root.\DIRECTORY_SEPARATOR) || !is_file($file)) {
            return null;
        }

        return $file;
    }

    /** Un chemin relatif, sans « .. », sans barre oblique inverse ni segment vide. */
    private static function isPlainPath(string $path): bool
    {
        if ('' === $path || str_contains($path, '\\') || str_contains($path, "\0")) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                return false;
            }
        }

        return true;
    }

    /**
     * Les fichiers d'une liste (stylesheets, scripts, preload) : chacun sous assets/, de la bonne extension, et
     * présent. Le message dit lequel et pourquoi : une feuille oubliée donnerait une page sans style, sans erreur.
     *
     * @param array<mixed>                         $config
     * @param \Closure(string): \RuntimeException $error
     *
     * @return list<string> les chemins tels que déclarés (« assets/theme.css »)
     */
    private static function assets(array $config, string $key, string $extension, string $directory, \Closure $error): array
    {
        $files = $config[$key] ?? [];
        if (!\is_array($files) || !array_is_list($files)) {
            throw $error(sprintf('« %s » doit être une liste de fichiers du dossier %s/ (« - %s/… »).', $key, self::ASSETS, self::ASSETS));
        }
        $paths = [];
        foreach ($files as $file) {
            if (!\is_string($file) || !str_starts_with($file, self::ASSETS.'/') || !self::isPlainPath($file)) {
                throw $error(sprintf('« %s » : « %s » doit être un fichier du dossier %s/ (par exemple %s/theme.%s), sans « .. ».', $key, \is_string($file) ? $file : '?', self::ASSETS, self::ASSETS, $extension));
            }
            if (strtolower(pathinfo($file, \PATHINFO_EXTENSION)) !== $extension) {
                throw $error(sprintf('« %s » : « %s » doit être un fichier .%s.', $key, $file, $extension));
            }
            if (null === self::assetPath($directory, substr($file, \strlen(self::ASSETS) + 1))) {
                throw $error(sprintf('« %s » : fichier introuvable dans le dossier du thème (%s).', $key, $directory.'/'.$file));
            }
            $paths[] = $file;
        }

        return $paths;
    }

    /**
     * @param array<mixed>                         $config
     * @param \Closure(string): \RuntimeException $error
     */
    private static function replacesEngineStyles(array $config, \Closure $error): bool
    {
        $value = $config['replaces_engine_styles'] ?? false;
        if (!\is_bool($value)) {
            throw $error('« replaces_engine_styles » vaut true ou false.');
        }
        if ($value && [] === ($config['stylesheets'] ?? [])) {
            throw $error('« replaces_engine_styles: true » demande au moins une feuille dans « stylesheets » : sans elle, les pages n\'auraient plus de styles.');
        }

        return $value;
    }

    /**
     * @param array<mixed>                         $config
     * @param \Closure(string): \RuntimeException $error
     */
    private static function text(array $config, string $key, string $default, \Closure $error): string
    {
        $value = $config[$key] ?? null;
        if (null === $value) {
            return $default;
        }
        if (!\is_string($value) && !is_numeric($value)) {
            throw $error(sprintf('« %s » doit être du texte.', $key));
        }

        return trim((string) $value);
    }

    /**
     * Chaque image déclarée est un fichier du dossier du thème, et il existe.
     *
     * @param array<mixed>                         $config
     * @param \Closure(string): \RuntimeException $error
     *
     * @return array<string, string|null>
     */
    private static function images(array $config, string $directory, \Closure $error): array
    {
        $images = [];
        foreach (self::IMAGES as $key) {
            $file = $config[$key] ?? null;
            if (null !== $file) {
                if (!\is_string($file) || '' === trim($file) || basename($file) !== $file) {
                    throw $error(sprintf('« %s » doit être le nom d\'un fichier du dossier du thème (sans barre oblique).', $key));
                }
                if (!is_file($directory.'/'.$file)) {
                    throw $error(sprintf('« %s » : fichier introuvable (%s).', $key, $directory.'/'.$file));
                }
                // Les clients mail lisent mal le webp et le svg : le logo des emails est un PNG, un JPEG ou un GIF.
                if ('email_logo' === $key && !preg_match('/\.(png|jpe?g|gif)$/i', $file)) {
                    throw $error('« email_logo » doit être un PNG, un JPEG ou un GIF : les clients mail lisent mal les autres formats.');
                }
            }
            $images[$key] = $file;
        }

        return $images;
    }

    /**
     * Les valeurs déclarées d'un bloc (couleurs, polices), vérifiées par la règle du bloc, par variable CSS.
     *
     * @param array<mixed>                         $config
     * @param array<string, string>                $allowed clé du fichier => variable CSS
     * @param \Closure(string): \RuntimeException $error
     *
     * @return array<string, string>
     */
    private static function block(array $config, string $block, array $allowed, BlockValue $rule, \Closure $error): array
    {
        $values = $config[$block] ?? [];
        if (!\is_array($values)) {
            throw $error(sprintf('« %s » doit être une liste de valeurs (%s).', $block, implode(', ', array_keys($allowed))));
        }
        $resolved = [];
        foreach ($values as $key => $value) {
            if (!\is_string($key) || !isset($allowed[$key])) {
                throw $error(sprintf('« %s.%s » inconnue (acceptées : %s).', $block, \is_string($key) ? $key : '?', implode(', ', array_keys($allowed))));
            }
            $value = \is_string($value) ? trim($value) : '';
            if (!$rule->accepts($value)) {
                throw $error($rule->expected($block.'.'.$key));
            }
            $resolved[$allowed[$key]] = $value;
        }

        return $resolved;
    }

    /**
     * Les textes de l'accueil propres au thème. Chaque clé vaut null quand la section n'est pas
     * déclarée : la page ne l'affiche pas.
     *
     * @param \Closure(string): \RuntimeException $error
     *
     * @return array{showcase: array<string, mixed>|null, author: array<string, mixed>|null, demo: array<string, mixed>|null}
     */
    private static function home(mixed $home, \Closure $error): array
    {
        if (!\is_array($home)) {
            throw $error('« home » doit être une liste de sections ('.implode(', ', array_keys(self::HOME_SHAPE)).').');
        }
        foreach (array_keys($home) as $key) {
            if (!isset(self::HOME_SHAPE[$key])) {
                throw $error(sprintf('section « home.%s » inconnue (acceptées : %s).', $key, implode(', ', array_keys(self::HOME_SHAPE))));
            }
        }

        return [
            'showcase' => self::section($home, 'showcase', $error),
            'author' => self::section($home, 'author', $error),
            'demo' => self::section($home, 'demo', $error),
        ];
    }

    /**
     * @param array<mixed>                         $home
     * @param \Closure(string): \RuntimeException $error
     *
     * @return array<string, mixed>|null
     */
    private static function section(array $home, string $key, \Closure $error): ?array
    {
        $section = $home[$key] ?? null;
        if (null === $section) {
            return null;
        }
        if (!\is_array($section) || [] === $section) {
            throw $error(sprintf('« home.%s » doit décrire la section, ou être absente.', $key));
        }
        self::checkShape($section, self::HOME_SHAPE[$key], 'home.'.$key, $error);

        /** @var array<string, mixed> $section vérifiée : ses clés sont celles de la forme */
        return $section;
    }

    /**
     * @param \Closure(string): \RuntimeException $error
     *
     * @return array{name: string, jobTitle: string}
     */
    private static function person(mixed $person, \Closure $error): array
    {
        if (!\is_array($person)) {
            throw $error(sprintf('« person » doit décrire une sous-section (%s).', implode(', ', array_keys(self::PERSON_SHAPE))));
        }
        self::checkShape($person, self::PERSON_SHAPE, 'person', $error);

        return ['name' => (string) $person['name'], 'jobTitle' => (string) $person['jobTitle']];
    }

    /**
     * Vérifie une section contre sa forme, et dit précisément ce qui cloche : le fichier est écrit à la
     * main, et le message est tout ce dont dispose celui qui l'écrit.
     *
     * @param array<mixed>                         $section tel que lu dans le YAML : ses clés ne sont pas forcément des chaînes
     * @param array<string, string|array<mixed>>   $shape
     * @param \Closure(string): \RuntimeException $error
     */
    private static function checkShape(array $section, array $shape, string $path, \Closure $error): void
    {
        foreach (array_keys($section) as $key) {
            if (!\is_string($key) || !isset($shape[$key])) {
                throw $error(sprintf('« %s.%s » inconnue (acceptées : %s).', $path, \is_string($key) ? $key : '?', implode(', ', array_keys($shape))));
            }
        }
        foreach ($shape as $key => $type) {
            $value = $section[$key] ?? null;
            $required = \is_string($type) && str_ends_with($type, '*');
            if (null === $value) {
                if ($required) {
                    throw $error(sprintf('« %s.%s » est obligatoire.', $path, $key));
                }
                continue;
            }
            if (\is_array($type)) {
                if (!\is_array($value)) {
                    throw $error(sprintf('« %s.%s » doit décrire une sous-section (%s).', $path, $key, implode(', ', array_keys($type))));
                }
                /** @var array<string, string|array<mixed>> $type */
                self::checkShape($value, $type, $path.'.'.$key, $error);
                continue;
            }
            if (str_starts_with($type, 'textes')) {
                if (!\is_array($value) || !array_is_list($value) || [] !== array_filter($value, static fn ($item) => !\is_string($item))) {
                    throw $error(sprintf('« %s.%s » doit être une liste de phrases. Attention : en YAML, une phrase qui contient « : » doit être entre guillemets.', $path, $key));
                }
                continue;
            }
            if (!\is_string($value)) {
                throw $error(sprintf('« %s.%s » doit être du texte. Attention : en YAML, une phrase qui contient « : » doit être entre guillemets.', $path, $key));
            }
        }
    }
}
