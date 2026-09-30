<?php

namespace App\Theme;

use Symfony\Component\Asset\Packages;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Le thème de l'instance : nom, puce, accroche, couleurs, images, textes propres à l'accueil.
 *
 * Sans rien, c'est le thème du moteur lui-même (« default »). Une instance installe les siens (un dossier par thème
 * dans THEMES_DIR, ou l'ancien BRANDING_DIR) et choisit le thème actif depuis l'admin — voir ThemeCatalog et
 * ActiveTheme. Un thème, c'est « theme.yaml » et ses images, plus au besoin des gabarits Twig (<dossier>/templates/,
 * voir ThemeTemplateLoader) et des fichiers servis tels quels (<dossier>/assets/). Un dossier qui n'a que l'ancien
 * « marque.yaml » est encore lu, jusqu'à la 4.0 (voir ThemeLoader).
 *
 * Règle simple, pour qu'une instance ne se retrouve jamais à parler d'une marque qui n'est pas la
 * sienne : **dès qu'un « theme.yaml » est fourni, plus rien du moteur ne subsiste**. Ni son nom, ni
 * ses images, ni les textes de son accueil (le fil rouge, « qui est derrière ») — l'instance déclare
 * les siens sous « home: », ou ces sections n'apparaissent pas.
 *
 * Les erreurs de ce fichier sont bruyantes : une couleur mal écrite ou une clé inconnue arrête la
 * page avec un message qui dit quoi corriger, plutôt que d'habiller l'instance à moitié. Le fichier est
 * lu et vérifié en entier au premier usage (ThemeLoader), et dès le démarrage par ThemeWarmer.
 *
 * Cette classe n'est que la façade de lecture de ThemeConfig.
 */
final class Theme implements ResetInterface
{
    public const string FILE = ThemeLoader::FILE;

    /** Le thème du moteur, servi quand l'instance ne monte rien. */
    public const string DEFAULT_ID = ThemeCatalog::DEFAULT;

    /** Le thème monté sur BRANDING_DIR. */
    public const string INSTANCE_ID = ThemeCatalog::INSTANCE;

    private ?ThemeConfig $config = null;

    private ?string $assetsVersion = null;

    public function __construct(
        private readonly ThemeSelection $selection,
        private readonly UrlGeneratorInterface $urls,
        private readonly Packages $assets,
        private readonly ThemeLoader $loader = new ThemeLoader(),
    ) {
    }

    /** Le thème lu et vérifié, une fois. */
    public function config(): ThemeConfig
    {
        return $this->config ??= $this->loader->load($this->selection->directory());
    }

    /** Relu à la requête suivante : un theme.yaml modifié se voit sans redémarrer, même en mode worker. */
    public function reset(): void
    {
        $this->config = null;
        $this->assetsVersion = null;
    }

    /** Vrai tant que l'instance n'a pas posé son thème : c'est celui du moteur qui s'affiche. */
    public function isDefault(): bool
    {
        return $this->config()->isDefault;
    }

    /** L'identifiant du thème servi : « default » (celui du moteur), « instance » (BRANDING_DIR) ou un dossier de THEMES_DIR. */
    public function id(): string
    {
        return $this->isDefault() ? self::DEFAULT_ID : $this->selection->id();
    }

    /** Vrai quand ce thème n'est montré qu'à l'administrateur qui le prévisualise (voir ActiveTheme). */
    public function isPreview(): bool
    {
        return $this->selection->isPreview();
    }

    /** Vrai quand le thème n'a que l'ancien marque.yaml : il reste lu jusqu'à la 4.0, mais doit être renommé. */
    public function usesLegacyFile(): bool
    {
        return ThemeLoader::LEGACY_FILE === $this->config()->file;
    }

    public function name(): string
    {
        return $this->config()->name;
    }

    /** La puce affichée à côté du nom, dans l'en-tête et le pied de page. Vide : pas de puce. */
    public function chip(): string
    {
        return $this->config()->chip;
    }

    /** Le <title> de l'accueil ; les autres pages ajoutent « · <nom> » au leur. */
    public function title(): string
    {
        return $this->config()->title;
    }

    /** Une phrase, affichée sous la marque dans le pied de page et dans les balises de partage. */
    public function tagline(): string
    {
        return $this->config()->tagline;
    }

    /** La signature des emails : le nom, et la puce quand il y en a une. */
    public function signature(): string
    {
        return '' === $this->chip() ? $this->name() : $this->name().' · '.$this->chip();
    }

    /** Le site de la marque, pour les données structurées. Vide : la plateforme parle d'elle-même. */
    public function url(): string
    {
        return $this->config()->url;
    }

    /**
     * La personne derrière les parcours et les exercices, pour les données structurées. Celle du moteur
     * ne vaut que pour sa marque : une autre instance n'en a pas.
     *
     * @return array{name: string, jobTitle: string}|null
     */
    public function person(): ?array
    {
        return $this->config()->person;
    }

    /** L'image du logo, ou null : l'en-tête n'affiche alors que le nom. */
    public function logoUrl(): ?string
    {
        return $this->imageUrl('logo', 'img/logo-84.webp');
    }

    /** Le même logo, en grand (le fil rouge de l'accueil, les données structurées). */
    public function logoLargeUrl(): ?string
    {
        return $this->imageUrl('logo', 'img/logo-168.webp');
    }

    /**
     * Le logo des emails, ou null (le nom seul). Le webp du site passe mal dans Outlook et bien des clients : le moteur
     * fournit un PNG, et le logo d'une marque n'est repris que s'il est en PNG, JPEG ou GIF.
     */
    public function emailLogoUrl(): ?string
    {
        $file = $this->config()->images['logo'] ?? null;
        if (null !== $file && !preg_match('/\.(png|jpe?g|gif)$/i', $file)) {
            return null;
        }

        return $this->imageUrl('logo', 'img/logo.png');
    }

    public function iconUrl(): ?string
    {
        return $this->imageUrl('icon', 'img/favicon.svg');
    }

    /** L'image des aperçus de partage (Open Graph), ou null : pas de balise og:image. */
    public function shareUrl(): ?string
    {
        return $this->imageUrl('share', 'img/og-forelse.png');
    }

    /** Les variables CSS de la marque, à poser après la feuille de styles. Vide quand rien n'est déclaré. */
    public function styles(): string
    {
        return ThemeStylesheet::render($this->config());
    }

    /**
     * Vrai tant que la marque ne déclare pas de couleurs : le site suit alors le thème du système (clair ou sombre).
     * Une palette imposée est claire (ThemeStylesheet::COLORS) : on ne la mélange pas aux encres du thème sombre.
     */
    public function followsSystemTheme(): bool
    {
        return [] === $this->config()->colors;
    }

    /**
     * Les textes de l'accueil propres à la marque. Chaque clé vaut null quand la section n'est pas
     * déclarée : la page ne l'affiche pas.
     *
     * @return array{showcase: array<string, mixed>|null, author: array<string, mixed>|null, demo: array<string, mixed>|null}
     */
    public function home(): array
    {
        return $this->config()->home;
    }

    /**
     * Les feuilles du thème, posées après celle du moteur (ou à sa place, voir replacesEngineStyles).
     *
     * @return list<string>
     */
    public function stylesheetUrls(): array
    {
        return array_map($this->assetUrl(...), $this->config()->stylesheets);
    }

    /**
     * Les scripts du thème, chargés après ceux du moteur : ils ajoutent (animations, composants d'accueil), ils ne
     * remplacent pas le JavaScript qui fait fonctionner la plateforme.
     *
     * @return list<string>
     */
    public function scriptUrls(): array
    {
        return array_map($this->assetUrl(...), $this->config()->scripts);
    }

    /**
     * Les polices du thème à précharger.
     *
     * @return list<string>
     */
    public function preloadUrls(): array
    {
        return array_map($this->assetUrl(...), $this->config()->preload);
    }

    /** Vrai quand les feuilles du thème remplacent celle du moteur : elle n'est alors plus chargée. */
    public function replacesEngineStyles(): bool
    {
        return $this->config()->replacesEngineStyles;
    }

    /** Le fichier de assets/ que le navigateur demande, ou null s'il n'a pas à être servi (voir ThemeLoader::assetPath). */
    public function assetPath(string $path): ?string
    {
        return $this->isDefault() ? null : ThemeLoader::assetPath($this->config()->directory, $path);
    }

    /**
     * L'URL d'un fichier de assets/ (« assets/theme.css »). Tous partagent la même version, la date du fichier le
     * plus récent du dossier : une feuille y trouve ses polices et ses images par des chemins relatifs, qui
     * changent donc d'URL avec elle dès que l'un d'eux change. Le cache immuable ne sert jamais un fichier périmé.
     */
    private function assetUrl(string $path): string
    {
        return $this->urls->generate('app_theme_asset', [
            'theme' => $this->id(),
            'version' => $this->assetsVersion(),
            'path' => substr($path, \strlen(ThemeLoader::ASSETS) + 1),
        ]);
    }

    /** La date du fichier le plus récent de assets/, calculée une fois par requête. */
    private function assetsVersion(): string
    {
        if (null !== $this->assetsVersion) {
            return $this->assetsVersion;
        }
        $latest = 0;
        $root = $this->config()->directory.'/'.ThemeLoader::ASSETS;
        if (is_dir($root)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $latest = max($latest, (int) $file->getMTime());
                }
            }
        }

        return $this->assetsVersion = (string) $latest;
    }

    /**
     * Une image déclarée par l'instance, servie par ThemeController et horodatée pour le cache.
     * Sans déclaration : l'image du moteur tant que c'est son thème, sinon rien.
     */
    private function imageUrl(string $key, string $engineAsset): ?string
    {
        $file = $this->config()->images[$key] ?? null;
        if (null === $file) {
            return $this->isDefault() ? $this->assets->getUrl($engineAsset) : null;
        }

        return $this->urls->generate('app_theme_image', [
            'theme' => $this->id(),
            'version' => (string) (filemtime($this->config()->directory.'/'.$file) ?: 0),
            'role' => $key,
        ]);
    }

    /** Le chemin du fichier d'une image déclarée, pour le contrôleur qui la sert. */
    public function imagePath(string $role): ?string
    {
        $file = $this->config()->images[$role] ?? null;
        if (null === $file) {
            return null;
        }
        $path = $this->config()->directory.'/'.$file;

        return is_file($path) ? $path : null;
    }
}
