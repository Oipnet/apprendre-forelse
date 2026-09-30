<?php

namespace App\Instance;

use App\Instance\Branding\BrandingConfig;
use App\Instance\Branding\BrandingLoader;
use App\Instance\Branding\BrandingStylesheet;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * L'identité de l'instance : nom, puce, accroche, couleurs, images, textes propres à l'accueil.
 *
 * Sans rien, c'est la marque du moteur lui-même. Une instance pose la sienne en montant un dossier
 * (BRANDING_DIR) qui contient « marque.yaml » et ses images ; elle peut y déposer en plus des gabarits
 * Twig (<dossier>/templates/) pour remplacer une page entière — voir BrandingTemplateLoader.
 *
 * Règle simple, pour qu'une instance ne se retrouve jamais à parler d'une marque qui n'est pas la
 * sienne : **dès qu'un « marque.yaml » est fourni, plus rien du moteur ne subsiste**. Ni son nom, ni
 * ses images, ni les textes de son accueil (le fil rouge, « qui est derrière ») — l'instance déclare
 * les siens sous « home: », ou ces sections n'apparaissent pas.
 *
 * Les erreurs de ce fichier sont bruyantes : une couleur mal écrite ou une clé inconnue arrête la
 * page avec un message qui dit quoi corriger, plutôt que d'habiller l'instance à moitié. Le fichier est
 * lu et vérifié en entier au premier usage (BrandingLoader), et dès le démarrage par BrandingWarmer.
 *
 * Cette classe n'est que la façade de lecture de BrandingConfig.
 */
final class Branding implements ResetInterface
{
    public const string FILE = BrandingLoader::FILE;

    private ?BrandingConfig $config = null;

    public function __construct(
        #[Autowire(env: 'resolve:BRANDING_DIR')]
        private readonly string $directory,
        private readonly UrlGeneratorInterface $urls,
        private readonly Packages $assets,
        private readonly BrandingLoader $loader = new BrandingLoader(),
    ) {
    }

    /** La marque lue et vérifiée, une fois. */
    public function config(): BrandingConfig
    {
        return $this->config ??= $this->loader->load($this->directory);
    }

    /** Relue à la requête suivante : un marque.yaml modifié se voit sans redémarrer, même en mode worker. */
    public function reset(): void
    {
        $this->config = null;
    }

    /** Vrai tant que l'instance n'a pas posé sa marque : c'est celle du moteur qui s'affiche. */
    public function isDefault(): bool
    {
        return $this->config()->isDefault;
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
        return BrandingStylesheet::render($this->config());
    }

    /**
     * Vrai tant que la marque ne déclare pas de couleurs : le site suit alors le thème du système (clair ou sombre).
     * Une palette imposée est claire (BrandingStylesheet::COLORS) : on ne la mélange pas aux encres du thème sombre.
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
     * Une image déclarée par l'instance, servie par BrandingController et horodatée pour le cache.
     * Sans déclaration : l'image du moteur tant que c'est sa marque, sinon rien.
     */
    private function imageUrl(string $key, string $engineAsset): ?string
    {
        $file = $this->config()->images[$key] ?? null;
        if (null === $file) {
            return $this->isDefault() ? $this->assets->getUrl($engineAsset) : null;
        }
        $path = $this->directory.'/'.$file;

        return $this->urls->generate('app_brand_image', ['role' => $key, 'v' => (string) (filemtime($path) ?: 0)]);
    }

    /** Le chemin du fichier d'une image déclarée, pour le contrôleur qui la sert. */
    public function imagePath(string $role): ?string
    {
        $file = $this->config()->images[$role] ?? null;
        if (null === $file) {
            return null;
        }
        $path = $this->directory.'/'.$file;

        return is_file($path) ? $path : null;
    }
}
