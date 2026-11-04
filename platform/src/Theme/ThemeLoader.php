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
    public const array KEYS = ['name', 'chip', 'title', 'tagline', 'url', 'person', 'authors', 'colors', 'editor', 'editor_light', 'fonts', 'logo', 'email_logo', 'icon', 'share', 'share_card', 'home', 'offers', 'stylesheets', 'scripts', 'preload', 'replaces_engine_styles', 'locales'];

    /**
     * Ce qu'une langue peut traduire, sous « locales: <langue>: » : les textes de la marque et les sections de
     * l'accueil. Le reste (couleurs, images, polices) ne dépend pas de la langue.
     */
    public const array LOCALE_KEYS = ['chip', 'title', 'tagline', 'home', 'person', 'authors', 'offers'];

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

    /**
     * L'habillage des images de partage que le moteur dessine (voir App\Share\ShareImageRenderer) : clé => couleur.
     * Les polices et le fond sont des fichiers du dossier du thème, à part (« font », « text_font », « image »).
     */
    public const array SHARE_CARD_COLORS = ['background', 'ink', 'accent', 'muted'];

    /** La personne derrière les contenus, pour les données structurées (auteur des parcours et des articles). */
    private const array NO_SHARE_CARD = ['image' => null, 'font' => null, 'text_font' => null, 'colors' => []];

    /** Les profils qu'un auteur peut lier (encart des articles, sameAs des données structurées), dans cet ordre. */
    public const array PERSON_LINKS = ['malt', 'linkedin', 'github', 'site'];

    private const array PERSON_SHAPE = [
        'name' => 'texte*',
        'jobTitle' => 'texte*',
        // Deux ou trois phrases sur soi, sous les articles : ce qu'on fait, pour qui, si l'on est disponible.
        'bio' => 'texte',
        // La page auteur (/auteur) : une présentation plus longue, en Markdown. Sans elle, la page reprend « bio ».
        'about' => 'texte',
        'links' => ['malt' => 'texte', 'linkedin' => 'texte', 'github' => 'texte', 'site' => 'texte'],
        // Une photo carrée, dans le dossier assets/ du thème (« assets/auteurs/ada.webp »).
        'portrait' => 'texte',
        // La page auteur, en vitrine : la ville (« Bordeaux »), une pastille (« Disponible pour une mission »), un lien
        // de prise de rendez-vous (https), l'accroche du dernier appel, les expertises (en avant) et les autres outils.
        'location' => 'texte',
        'availability' => 'texte',
        'booking' => 'texte',
        'cta' => 'texte',
        'expertise' => 'textes',
        'skills' => 'textes',
    ];

    /**
     * Les listes de fiches de la page auteur, et la forme de chaque fiche : les chiffres clés, les services, les
     * mentions (« Ils en parlent ») et les avis. Une liste de phrases ne suffit pas : chaque fiche a deux ou trois champs.
     */
    private const array PERSON_ITEMS = [
        'stats' => ['value' => 'texte*', 'label' => 'texte*'],
        'services' => ['title' => 'texte*', 'text' => 'texte*'],
        'press' => ['name' => 'texte*', 'text' => 'texte', 'url' => 'texte'],
        'testimonials' => ['quote' => 'texte*', 'author' => 'texte*'],
    ];

    /** Ce qu'une langue traduit de la personne : le reste (nom, liens, photo, rendez-vous) ne change pas. */
    private const array PERSON_LOCALE_SHAPE = [
        'jobTitle' => 'texte', 'bio' => 'texte', 'about' => 'texte', 'location' => 'texte', 'availability' => 'texte',
        'cta' => 'texte', 'expertise' => 'textes', 'skills' => 'textes',
    ];

    /**
     * La page des offres (/formations, voir OffersController) : ce que la personne du thème vend de son temps,
     * formations et audits, avec les prix. Chaque section est facultative, sauf l'en-tête et les offres elles-mêmes.
     * Une clé dont la forme est ['fiches' => …] est une liste de fiches de cette forme.
     */
    private const array OFFERS_SHAPE = [
        // Le <title> et la description de la page, quand ceux tirés de l'en-tête ne conviennent pas.
        'seo' => ['title' => 'texte', 'description' => 'texte'],
        'hero' => ['eyebrow' => 'texte', 'title' => 'texte*', 'lead' => 'texte', 'button' => 'texte', 'more' => 'texte', 'note' => 'texte'],
        // Le lien de prise de rendez-vous (https) ; sans lui, celui de « person.booking », sinon la page de contact.
        'booking' => 'texte',
        // Un extrait de code et ce que ses tests vérifient, à côté de l'en-tête.
        'showcase' => ['file' => 'texte', 'badge' => 'texte', 'code' => 'texte*', 'checks' => 'textes', 'result' => 'texte'],
        'stats' => ['fiches' => ['value' => 'texte*', 'label' => 'texte*']],
        'proof' => 'texte',
        'catalog' => ['eyebrow' => 'texte', 'title' => 'texte*', 'lead' => 'texte'],
        // Les offres : « id » est l'ancre (#audit-acces), « amount » le prix en chiffres pour les données structurées.
        'items' => ['fiches' => [
            'id' => 'texte*', 'tag' => 'texte', 'title' => 'texte*', 'audience' => 'texte', 'features' => 'textes',
            'price' => 'texte*', 'unit' => 'texte', 'amount' => 'texte', 'note' => 'texte', 'cta' => 'texte*',
        ]],
        'reasons' => ['eyebrow' => 'texte', 'title' => 'texte*', 'items' => ['fiches' => ['title' => 'texte*', 'text' => 'texte*']]],
        'program' => ['title' => 'texte*', 'lead' => 'texte', 'days' => ['fiches' => ['label' => 'texte*', 'title' => 'texte*', 'text' => 'texte*']]],
        // Un exemple de constat d'audit ; une valeur entre `accents graves` s'affiche comme du code.
        'example' => [
            'title' => 'texte*', 'lead' => 'texte', 'link' => ['text' => 'texte*', 'url' => 'texte*'], 'severity' => 'texte',
            'label' => 'texte', 'finding' => 'texte*', 'rows' => ['fiches' => ['label' => 'texte*', 'value' => 'texte*']],
        ],
        'steps' => ['title' => 'texte*', 'items' => ['fiches' => ['title' => 'texte*', 'text' => 'texte*']]],
        'faq' => ['title' => 'texte*', 'items' => ['fiches' => ['question' => 'texte*', 'answer' => 'texte*']]],
        'cta' => ['eyebrow' => 'texte', 'title' => 'texte*', 'button' => 'texte*'],
    ];

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
            chip: self::text($config, 'chip', '', $error),
            // Le titre non déclaré retombe sur le nom, jamais sur celui du moteur.
            title: self::text($config, 'title', $name, $error),
            tagline: self::text($config, 'tagline', 'Apprendre à développer en codant dans le navigateur, sans vidéo ni installation.', $error),
            url: self::text($config, 'url', '', $error),
            images: $engine ? array_fill_keys(self::IMAGES, null) : self::images($config, $directory, $error),
            colors: self::block($config, 'colors', ThemeStylesheet::COLORS, new HexColor(), $error),
            editor: self::block($config, 'editor', ThemeStylesheet::EDITOR_COLORS, new HexColor(), $error),
            editorLight: self::block($config, 'editor_light', ThemeStylesheet::EDITOR_COLORS, new HexColor(), $error),
            fonts: self::block($config, 'fonts', ThemeStylesheet::FONTS, new FontStack(), $error),
            home: self::home($config['home'] ?? [], $error),
            shareCard: $engine ? self::NO_SHARE_CARD : self::shareCard($config['share_card'] ?? [], $directory, $error),
            person: isset($config['person']) ? self::person($config['person'], 'person', $directory, $error) : null,
            offers: isset($config['offers']) ? self::offers($config['offers'], 'offers', $error) : null,
            authors: self::authors($config['authors'] ?? null, $directory, $error),
            stylesheets: self::assets($config, 'stylesheets', 'css', $directory, $error),
            scripts: self::assets($config, 'scripts', 'js', $directory, $error),
            preload: self::assets($config, 'preload', 'woff2', $directory, $error),
            replacesEngineStyles: self::replacesEngineStyles($config, $error),
            locales: self::locales($config['locales'] ?? null, $error),
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
     * « share_card » : le fond (PNG ou JPEG, 1200 × 630), les polices (TrueType ou OpenType : la bibliothèque d'images
     * ne lit pas le woff2) et les couleurs des images de partage. Tout est facultatif : ce qui manque vient du moteur.
     *
     * @param \Closure(string): \RuntimeException $error
     *
     * @return array{image: string|null, font: string|null, text_font: string|null, colors: array<string, string>}
     */
    private static function shareCard(mixed $card, string $directory, \Closure $error): array
    {
        if (!\is_array($card)) {
            throw $error('« share_card » doit décrire les images de partage (image, font, text_font, '.implode(', ', self::SHARE_CARD_COLORS).').');
        }
        $resolved = self::NO_SHARE_CARD;
        $color = new HexColor();
        foreach ($card as $key => $value) {
            $path = 'share_card.'.(\is_string($key) ? $key : '?');
            if (\in_array($key, self::SHARE_CARD_COLORS, true)) {
                $value = \is_string($value) ? trim($value) : '';
                if (!$color->accepts($value)) {
                    throw $error($color->expected($path));
                }
                $resolved['colors'][$key] = $value;
                continue;
            }
            $extensions = match ($key) {
                'image' => ['png', 'jpg', 'jpeg'],
                'font', 'text_font' => ['ttf', 'otf'],
                default => throw $error(sprintf('« %s » inconnue (acceptées : image, font, text_font, %s).', $path, implode(', ', self::SHARE_CARD_COLORS))),
            };
            if (!\is_string($value) || !self::isPlainPath($value)) {
                throw $error(sprintf('« %s » doit être un fichier du dossier du thème, sans « .. ».', $path));
            }
            if (!\in_array(strtolower(pathinfo($value, \PATHINFO_EXTENSION)), $extensions, true)) {
                throw $error(sprintf('« %s » : « %s » doit être un fichier %s.', $path, $value, implode(', ', array_map(static fn (string $e) => '.'.$e, $extensions))));
            }
            // Comme pour assets/ : un lien symbolique qui sort du dossier du thème est refusé.
            $root = realpath($directory);
            $file = realpath($directory.'/'.$value);
            if (false === $root || false === $file || !str_starts_with($file, $root.\DIRECTORY_SEPARATOR) || !is_file($file)) {
                throw $error(sprintf('« %s » : fichier introuvable dans le dossier du thème (%s).', $path, $directory.'/'.$value));
            }
            $resolved[$key] = $value;
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
     * Les textes du thème dans d'autres langues que celle de theme.yaml. Une section de l'accueil traduite remplace
     * la section entière : elle se vérifie comme l'originale, et ce qui n'est pas traduit reste dans la langue du
     * fichier.
     *
     * @param \Closure(string): \RuntimeException $error
     *
     * @return array<string, array{chip?: string, title?: string, tagline?: string, home: array<string, array<string, mixed>>}>
     */
    private static function locales(mixed $locales, \Closure $error): array
    {
        if (null === $locales) {
            return [];
        }
        if (!\is_array($locales) || array_is_list($locales)) {
            throw $error(sprintf('« locales » associe une langue (en, de…) à ses textes (%s).', implode(', ', self::LOCALE_KEYS)));
        }
        $result = [];
        foreach ($locales as $locale => $texts) {
            if (!\is_string($locale) || 1 !== preg_match('/^[a-z]{2}$/', $locale)) {
                throw $error(sprintf('« locales.%s » : une langue s\'écrit en deux lettres minuscules (en, de…).', $locale));
            }
            $localeError = static fn (string $message) => $error(sprintf('locales.%s, %s', $locale, $message));
            if (!\is_array($texts) || array_is_list($texts)) {
                throw $localeError(sprintf('ses textes sont attendus (%s).', implode(', ', self::LOCALE_KEYS)));
            }
            foreach (array_keys($texts) as $key) {
                if (!\in_array($key, self::LOCALE_KEYS, true)) {
                    throw $localeError(sprintf('clé « %s » inconnue (acceptées : %s).', $key, implode(', ', self::LOCALE_KEYS)));
                }
            }
            $translated = ['home' => array_filter(self::home($texts['home'] ?? [], $localeError))];
            foreach (['chip', 'title', 'tagline'] as $key) {
                if (isset($texts[$key])) {
                    $translated[$key] = self::text($texts, $key, '', $localeError);
                }
            }
            if (isset($texts['person'])) {
                $translated['person'] = self::personTexts($texts['person'], 'person', $localeError);
            }
            // Une page des offres traduite remplace l'originale en entier : prix, ancres et rendez-vous compris.
            if (isset($texts['offers'])) {
                $translated['offers'] = self::offers($texts['offers'], 'offers', $localeError);
            }
            if (isset($texts['authors'])) {
                if (!\is_array($texts['authors']) || array_is_list($texts['authors'])) {
                    throw $localeError('« authors » associe l\'identifiant d\'un auteur à ses textes traduits.');
                }
                foreach ($texts['authors'] as $id => $authorTexts) {
                    $translated['authors'][(string) $id] = self::personTexts($authorTexts, 'authors.'.$id, $localeError);
                }
            }
            $result[$locale] = $translated;
        }

        return $result;
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
    private static function person(mixed $person, string $path, string $directory, \Closure $error): array
    {
        if (!\is_array($person)) {
            throw $error(sprintf('« %s » doit décrire une sous-section (%s).', $path, implode(', ', array_keys(self::PERSON_SHAPE))));
        }
        $result = self::personItems($person, $path, $error);
        self::checkShape(array_diff_key($person, self::PERSON_ITEMS), self::PERSON_SHAPE, $path, $error);
        $result = ['name' => (string) $person['name'], 'jobTitle' => (string) $person['jobTitle'], ...$result];
        foreach (['bio', 'about', 'location', 'availability', 'cta'] as $key) {
            if (isset($person[$key]) && '' !== trim((string) $person[$key])) {
                $result[$key] = trim((string) $person[$key]);
            }
        }
        foreach (['expertise', 'skills'] as $key) {
            $list = array_values(array_filter(array_map(trim(...), $person[$key] ?? []), static fn ($item) => '' !== $item));
            if ([] !== $list) {
                $result[$key] = $list;
            }
        }
        if (isset($person['booking'])) {
            $result['booking'] = self::httpsUrl((string) $person['booking'], $path.'.booking', $error);
        }
        $links = [];
        foreach (self::PERSON_LINKS as $key) {
            $url = trim((string) ($person['links'][$key] ?? ''));
            if ('' === $url) {
                continue;
            }
            $links[$key] = self::httpsUrl($url, $path.'.links.'.$key, $error);
        }
        if ([] !== $links) {
            $result['links'] = $links;
        }
        if (isset($person['portrait'])) {
            $file = (string) $person['portrait'];
            if (!str_starts_with($file, self::ASSETS.'/') || null === self::assetPath($directory, substr($file, \strlen(self::ASSETS) + 1))) {
                throw $error(sprintf('« %s.portrait » doit être une image du dossier %s/ du thème (%s).', $path, self::ASSETS, $file));
            }
            $result['portrait'] = $file;
        }

        return $result;
    }

    /**
     * Les autres auteurs des articles, par identifiant (celui de leur page, /auteurs/<identifiant>, et de la clé
     * « author » d'un article). « person » reste l'auteur par défaut, et celui des parcours.
     *
     * @param \Closure(string): \RuntimeException $error
     *
     * @return array<string, array<string, mixed>>
     */
    private static function authors(mixed $authors, string $directory, \Closure $error): array
    {
        if (null === $authors) {
            return [];
        }
        if (!\is_array($authors) || array_is_list($authors)) {
            throw $error('« authors » associe l\'identifiant d\'un auteur (« ada-lovelace ») à sa description (name, jobTitle, bio…).');
        }
        $result = [];
        foreach ($authors as $id => $author) {
            if (!\is_string($id) || 1 !== preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $id)) {
                throw $error(sprintf('« authors.%s » : l\'identifiant d\'un auteur s\'écrit en minuscules, sans accent (« ada-lovelace »).', $id));
            }
            $result[$id] = self::person($author, 'authors.'.$id, $directory, $error);
        }

        return $result;
    }

    /**
     * @param \Closure(string): \RuntimeException $error
     *
     * @return array<string, string>
     */
    private static function personTexts(mixed $texts, string $path, \Closure $error): array
    {
        if (!\is_array($texts)) {
            throw $error(sprintf('« %s » doit décrire une sous-section (%s).', $path, implode(', ', array_keys(self::PERSON_LOCALE_SHAPE))));
        }
        $items = self::personItems($texts, $path, $error);
        $texts = array_diff_key($texts, self::PERSON_ITEMS);
        self::checkShape($texts, self::PERSON_LOCALE_SHAPE, $path, $error);

        return [...array_map(static fn ($text) => \is_array($text) ? array_values(array_map(trim(...), $text)) : trim((string) $text), $texts), ...$items];
    }

    /**
     * Les listes de fiches d'une personne (PERSON_ITEMS), vérifiées : chaque fiche a ses champs obligatoires, et rien
     * d'autre. Une adresse de mention (« press.url ») est en https.
     *
     * @param array<mixed>                         $person
     * @param \Closure(string): \RuntimeException $error
     *
     * @return array<string, list<array<string, string>>>
     */
    private static function personItems(array $person, string $path, \Closure $error): array
    {
        $result = [];
        foreach (self::PERSON_ITEMS as $key => $shape) {
            if (!isset($person[$key])) {
                continue;
            }
            if (!\is_array($person[$key]) || !array_is_list($person[$key])) {
                throw $error(sprintf('« %s.%s » doit être une liste de fiches (%s).', $path, $key, implode(', ', array_keys($shape))));
            }
            foreach ($person[$key] as $index => $item) {
                $itemPath = sprintf('%s.%s[%d]', $path, $key, $index);
                if (!\is_array($item)) {
                    throw $error(sprintf('« %s » doit être une fiche (%s).', $itemPath, implode(', ', array_keys($shape))));
                }
                // Un chiffre (« value: 58 ») est lu comme un nombre : c'est bien du texte à afficher.
                $item = array_map(static fn ($value) => \is_int($value) || \is_float($value) ? (string) $value : $value, $item);
                self::checkShape($item, $shape, $itemPath, $error);
                $item = array_map(static fn ($text) => trim((string) $text), $item);
                if (isset($item['url'])) {
                    $item['url'] = self::httpsUrl($item['url'], $itemPath.'.url', $error);
                }
                $result[$key][] = $item;
            }
        }

        return $result;
    }

    /**
     * La page des offres, vérifiée contre OFFERS_SHAPE : au moins une offre, des ancres uniques, un prix en chiffres
     * et des liens sûrs (le rendez-vous en https, le lien de l'exemple en https ou sur le site).
     *
     * @param \Closure(string): \RuntimeException $error
     *
     * @return array<string, mixed>
     */
    private static function offers(mixed $offers, string $path, \Closure $error): array
    {
        if (!\is_array($offers) || array_is_list($offers)) {
            throw $error(sprintf('« %s » doit décrire la page (%s).', $path, implode(', ', array_keys(self::OFFERS_SHAPE))));
        }
        foreach (['hero', 'items'] as $key) {
            if (!isset($offers[$key])) {
                throw $error(sprintf('« %s.%s » est obligatoire.', $path, $key));
            }
        }
        $offers = self::numbersAsText($offers);
        self::checkShape($offers, self::OFFERS_SHAPE, $path, $error);
        if ([] === $offers['items']) {
            throw $error(sprintf('« %s.items » doit lister au moins une offre.', $path));
        }
        $ids = [];
        foreach ($offers['items'] as $index => $item) {
            $itemPath = sprintf('%s.items[%d]', $path, $index);
            if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $item['id'])) {
                throw $error(sprintf('« %s.id » sert d\'ancre : des minuscules, des chiffres et des tirets (%s).', $itemPath, $item['id']));
            }
            if (isset($ids[$item['id']])) {
                throw $error(sprintf('« %s.id » : l\'ancre « %s » est déjà prise.', $itemPath, $item['id']));
            }
            $ids[$item['id']] = true;
            if (isset($item['amount']) && 1 !== preg_match('/^\d+(?:\.\d{1,2})?$/', $item['amount'])) {
                throw $error(sprintf('« %s.amount » est un prix en chiffres, sans unité (1200 ou 1200.50) : %s.', $itemPath, $item['amount']));
            }
        }
        if (isset($offers['booking'])) {
            $offers['booking'] = self::httpsUrl($offers['booking'], $path.'.booking', $error);
        }
        if (isset($offers['example']['link'])) {
            $url = trim($offers['example']['link']['url']);
            if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
                $url = self::httpsUrl($url, $path.'.example.link.url', $error);
            }
            $offers['example']['link']['url'] = $url;
        }

        /** @var array<string, mixed> $offers vérifiée : ses clés sont celles de la forme */
        return $offers;
    }

    /**
     * Un chiffre écrit sans guillemets (« value: 58 », « amount: 1200 ») est lu comme un nombre par le YAML : c'est
     * bien du texte à afficher.
     *
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private static function numbersAsText(array $values): array
    {
        return array_map(static fn ($value) => match (true) {
            \is_array($value) => self::numbersAsText($value),
            \is_int($value), \is_float($value) => (string) $value,
            default => $value,
        }, $values);
    }

    /** @param \Closure(string): \RuntimeException $error */
    private static function httpsUrl(string $url, string $path, \Closure $error): string
    {
        $url = trim($url);
        if (!str_starts_with($url, 'https://') || false === filter_var($url, \FILTER_VALIDATE_URL)) {
            throw $error(sprintf('« %s » doit être une adresse en https:// (%s).', $path, $url));
        }

        return $url;
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
            if (\is_array($type) && isset($type['fiches'])) {
                $fields = $type['fiches'];
                if (!\is_array($value) || !array_is_list($value)) {
                    throw $error(sprintf('« %s.%s » doit être une liste de fiches (%s).', $path, $key, implode(', ', array_keys($fields))));
                }
                foreach ($value as $index => $item) {
                    $itemPath = sprintf('%s.%s[%d]', $path, $key, $index);
                    if (!\is_array($item) || array_is_list($item)) {
                        throw $error(sprintf('« %s » doit être une fiche (%s).', $itemPath, implode(', ', array_keys($fields))));
                    }
                    /** @var array<string, string|array<mixed>> $fields */
                    self::checkShape($item, $fields, $itemPath, $error);
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
