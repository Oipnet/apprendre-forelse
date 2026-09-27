<?php

namespace App\Instance\Branding;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Lit marque.yaml et le vérifie en entier, en une fois : une erreur (une couleur mal écrite, une clé inconnue,
 * une section d'accueil incomplète) est signalée avec le chemin de sa clé dès le chargement, et non au rendu
 * de la page qui s'en sert. Sans marque.yaml, c'est celui du moteur (marque.yaml, à côté de cette classe).
 *
 * Appelé par Branding au premier usage, par BrandingWarmer au démarrage, et par app:marque:verifier.
 */
final readonly class BrandingLoader
{
    public const string FILE = 'marque.yaml';

    /** Les clés acceptées à la racine de marque.yaml. */
    public const array KEYS = ['name', 'chip', 'title', 'tagline', 'url', 'colors', 'editor', 'fonts', 'logo', 'icon', 'share', 'home'];

    /** Les images que l'instance peut fournir, et la route qui les sert (voir BrandingController). */
    public const array IMAGES = ['logo', 'icon', 'share'];

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

    /** La personne derrière les contenus : la marque du moteur seulement (données structurées). */
    private const array PERSON_SHAPE = ['name' => 'texte*', 'jobTitle' => 'texte*'];

    public function __construct(
        /** La marque du moteur : son accueil, sa Person. */
        private string $engineDirectory = __DIR__,
    ) {
    }

    /**
     * @throws \RuntimeException marque.yaml illisible ou invalide, avec le chemin de la clé en cause
     */
    public function load(string $directory): BrandingConfig
    {
        $path = $directory.'/'.self::FILE;
        if ('' === $directory || !is_file($path)) {
            return $this->read($this->engineDirectory, true);
        }

        return $this->read($directory, false);
    }

    private function read(string $directory, bool $engine): BrandingConfig
    {
        $path = $directory.'/'.self::FILE;
        try {
            $config = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new \RuntimeException(sprintf('Marque : %s est illisible — %s', $path, $e->getMessage()), previous: $e);
        }
        if (!\is_array($config)) {
            throw new \RuntimeException(sprintf('Marque : %s doit décrire la marque (voir la documentation).', $path));
        }
        $error = static fn (string $message) => new \RuntimeException(sprintf('Marque (%s/%s) : %s', $directory, self::FILE, $message));

        $keys = $engine ? [...self::KEYS, 'person'] : self::KEYS;
        foreach (array_keys($config) as $key) {
            if (!\in_array($key, $keys, true)) {
                throw $error(sprintf('clé « %s » inconnue (acceptées : %s).', $key, implode(', ', $keys)));
            }
        }
        if (!\is_string($config['name'] ?? null) || '' === trim($config['name'])) {
            throw $error('« name » est obligatoire : c\'est le nom affiché partout.');
        }
        $name = self::text($config, 'name', '', $error);

        return new BrandingConfig(
            isDefault: $engine,
            directory: $directory,
            name: $name,
            chip: self::text($config, 'chip', 'apprendre', $error),
            // Le titre non déclaré retombe sur le nom, jamais sur celui du moteur.
            title: self::text($config, 'title', $name, $error),
            tagline: self::text($config, 'tagline', 'Apprendre à développer en codant dans le navigateur, sans vidéo ni installation.', $error),
            url: self::text($config, 'url', '', $error),
            images: $engine ? array_fill_keys(self::IMAGES, null) : self::images($config, $directory, $error),
            colors: self::block($config, 'colors', BrandingStylesheet::COLORS, new HexColor(), $error),
            editor: self::block($config, 'editor', BrandingStylesheet::EDITOR_COLORS, new HexColor(), $error),
            fonts: self::block($config, 'fonts', BrandingStylesheet::FONTS, new FontStack(), $error),
            home: self::home($config['home'] ?? [], $error),
            person: $engine && isset($config['person']) ? self::person($config['person'], $error) : null,
        );
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
     * Chaque image déclarée est un fichier du dossier de marque, et il existe.
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
                    throw $error(sprintf('« %s » doit être le nom d\'un fichier du dossier de marque (sans barre oblique).', $key));
                }
                if (!is_file($directory.'/'.$file)) {
                    throw $error(sprintf('« %s » : fichier introuvable (%s).', $key, $directory.'/'.$file));
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
     * Les textes de l'accueil propres à la marque. Chaque clé vaut null quand la section n'est pas
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
