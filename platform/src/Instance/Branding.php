<?php

namespace App\Instance;

use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

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
 * page avec un message qui dit quoi corriger, plutôt que d'habiller l'instance à moitié.
 */
final class Branding
{
    public const string FILE = 'marque.yaml';

    /** Les clés acceptées à la racine de marque.yaml. */
    private const array KEYS = ['name', 'chip', 'title', 'tagline', 'url', 'colors', 'editor', 'fonts', 'logo', 'icon', 'share', 'home'];

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
     * Les couleurs réglables et la variable CSS qu'elles écrivent (voir playground/src/site.css).
     * Le thème sombre des éditeurs n'en dépend pas : il reste celui du moteur.
     */
    private const array COLORS = [
        'accent' => '--lp-rust',
        'accent-line' => '--lp-rust-line',
        'gold' => '--lp-gold',
        'background' => '--lp-bg',
        'background-2' => '--lp-bg-2',
        'surface' => '--lp-card',
        'line' => '--lp-line',
        'ink' => '--lp-ink',
        'dark' => '--lp-dark',
        'dark-ink' => '--lp-dark-ink',
        'success' => '--lp-green',
    ];

    /**
     * Les couleurs du thème sombre, celui de l'éditeur d'exercice et de l'atelier (:root dans site.css).
     * Déclarées à part du thème clair, et jamais déduites de lui : une couleur claire assombrie
     * automatiquement, c'est un contraste perdu au hasard. Absentes, celles du moteur restent.
     */
    private const array EDITOR_COLORS = [
        'accent' => '--accent',
        'gold' => '--gold',
        'background' => '--bg',
        'surface' => '--panel',
        'surface-2' => '--panel-2',
        'line' => '--border',
        'ink' => '--text',
        'muted' => '--muted',
        'success' => '--ok',
        'error' => '--ko',
    ];

    private const array FONTS = ['serif' => '--lp-serif', 'sans' => '--lp-sans', 'mono' => '--lp-mono'];

    /** Les images que l'instance peut fournir, et la route qui les sert (voir BrandingController). */
    private const array IMAGES = ['logo', 'icon', 'share'];

    /** @var array<string, mixed>|null contenu de marque.yaml, lu une fois */
    private ?array $config = null;

    public function __construct(
        #[Autowire(env: 'resolve:BRANDING_DIR')]
        private readonly string $directory,
        private readonly UrlGeneratorInterface $urls,
        private readonly Packages $assets,
    ) {
    }

    /** Vrai tant que l'instance n'a pas posé sa marque : c'est celle du moteur qui s'affiche. */
    public function isDefault(): bool
    {
        return [] === $this->config();
    }

    public function name(): string
    {
        return $this->text('name', 'Forelse');
    }

    /** La puce affichée à côté du nom, dans l'en-tête et le pied de page. Vide : pas de puce. */
    public function chip(): string
    {
        return $this->text('chip', 'apprendre');
    }

    /** Le <title> de l'accueil ; les autres pages ajoutent « · <nom> » au leur. */
    public function title(): string
    {
        return $this->text('title', $this->isDefault() ? 'Forelse · apprendre à développer' : $this->name());
    }

    /** Une phrase, affichée sous la marque dans le pied de page et dans les balises de partage. */
    public function tagline(): string
    {
        return $this->text('tagline', 'Apprendre à développer en codant dans le navigateur, sans vidéo ni installation.');
    }

    /** La signature des emails : le nom, et la puce quand il y en a une. */
    public function signature(): string
    {
        return '' === $this->chip() ? $this->name() : $this->name().' · '.$this->chip();
    }

    /** Le site de la marque, pour les données structurées. Vide : la plateforme parle d'elle-même. */
    public function url(): string
    {
        return $this->text('url', $this->isDefault() ? 'https://forelse.fr' : '');
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

    public function iconUrl(): ?string
    {
        return $this->imageUrl('icon', 'img/favicon.svg');
    }

    /** L'image des aperçus de partage (Open Graph), ou null : pas de balise og:image. */
    public function shareUrl(): ?string
    {
        return $this->imageUrl('share', 'img/og-forelse.png');
    }

    /**
     * Les variables CSS de la marque, à poser après la feuille de styles. Vide quand rien n'est déclaré.
     *
     * Les valeurs sont vérifiées à la lecture (couleur hexadécimale, pile de polices sans caractère
     * spécial) : rien de ce qui sort d'ici ne peut refermer la balise <style>.
     */
    public function styles(): string
    {
        $colors = $this->map('colors', self::COLORS);
        $css = self::rule('body.site-page', [...$colors, ...$this->map('fonts', self::FONTS)]);
        // Le fond de la page est aussi posé sur <html> (pas d'éclair blanc au chargement) : il suit.
        if (null !== ($background = $colors['--lp-bg'] ?? null)) {
            $css .= 'html:has(> body.site-page){background:'.$background.'}';
        }

        // Le thème sombre, que les pages du site redéfinissent pour elles : seuls l'éditeur et l'atelier le portent.
        return $css.self::rule(':root', $this->map('editor', self::EDITOR_COLORS));
    }

    /**
     * @param array<string, string> $declarations variable CSS => valeur
     */
    private static function rule(string $selector, array $declarations): string
    {
        if ([] === $declarations) {
            return '';
        }
        $body = [];
        foreach ($declarations as $variable => $value) {
            $body[] = $variable.':'.$value;
        }

        return $selector.'{'.implode(';', $body).'}';
    }

    /**
     * Les textes de l'accueil propres à la marque. Chaque clé vaut null quand la section n'est pas
     * déclarée : la page ne l'affiche pas.
     *
     * @return array{showcase: array<string, mixed>|null, author: array<string, mixed>|null, demo: array<string, mixed>|null}
     */
    public function home(): array
    {
        $home = $this->config()['home'] ?? ($this->isDefault() ? self::defaultHome() : []);
        if (!\is_array($home)) {
            throw $this->error('« home » doit être une liste de sections ('.implode(', ', array_keys(self::HOME_SHAPE)).').');
        }
        foreach (array_keys($home) as $key) {
            if (!isset(self::HOME_SHAPE[$key])) {
                throw $this->error(sprintf('section « home.%s » inconnue (acceptées : %s).', $key, implode(', ', array_keys(self::HOME_SHAPE))));
            }
        }

        return [
            'showcase' => $this->section($home, 'showcase'),
            'author' => $this->section($home, 'author'),
            'demo' => $this->section($home, 'demo'),
        ];
    }

    /**
     * @param array<string, mixed> $home
     *
     * @return array<string, mixed>|null
     */
    private function section(array $home, string $key): ?array
    {
        $section = $home[$key] ?? null;
        if (null === $section) {
            return null;
        }
        if (!\is_array($section) || [] === $section) {
            throw $this->error(sprintf('« home.%s » doit décrire la section, ou être absente.', $key));
        }
        $this->checkShape($section, self::HOME_SHAPE[$key], 'home.'.$key);

        return $section;
    }

    /**
     * Vérifie une section contre sa forme, et dit précisément ce qui cloche : le fichier est écrit à la
     * main, et le message est tout ce dont dispose celui qui l'écrit.
     *
     * @param array<mixed>                       $section tel que lu dans le YAML : ses clés ne sont pas forcément des chaînes
     * @param array<string, string|array<mixed>> $shape
     */
    private function checkShape(array $section, array $shape, string $path): void
    {
        foreach (array_keys($section) as $key) {
            if (!\is_string($key) || !isset($shape[$key])) {
                throw $this->error(sprintf('« %s.%s » inconnue (acceptées : %s).', $path, \is_string($key) ? $key : '?', implode(', ', array_keys($shape))));
            }
        }
        foreach ($shape as $key => $type) {
            $value = $section[$key] ?? null;
            $required = \is_string($type) && str_ends_with($type, '*');
            if (null === $value) {
                if ($required) {
                    throw $this->error(sprintf('« %s.%s » est obligatoire.', $path, $key));
                }
                continue;
            }
            if (\is_array($type)) {
                if (!\is_array($value)) {
                    throw $this->error(sprintf('« %s.%s » doit décrire une sous-section (%s).', $path, $key, implode(', ', array_keys($type))));
                }
                $this->checkShape($value, $type, $path.'.'.$key);
                continue;
            }
            if (str_starts_with($type, 'textes')) {
                if (!\is_array($value) || !array_is_list($value) || [] !== array_filter($value, static fn ($item) => !\is_string($item))) {
                    throw $this->error(sprintf('« %s.%s » doit être une liste de phrases. Attention : en YAML, une phrase qui contient « : » doit être entre guillemets.', $path, $key));
                }
                continue;
            }
            if (!\is_string($value)) {
                throw $this->error(sprintf('« %s.%s » doit être du texte. Attention : en YAML, une phrase qui contient « : » doit être entre guillemets.', $path, $key));
            }
        }
    }

    private function text(string $key, string $default): string
    {
        $value = $this->config()[$key] ?? null;
        if (null === $value) {
            return $default;
        }
        if (!\is_string($value) && !is_numeric($value)) {
            throw $this->error(sprintf('« %s » doit être du texte.', $key));
        }

        return trim((string) $value);
    }

    /**
     * Une image déclarée par l'instance, servie par BrandingController et horodatée pour le cache.
     * Sans déclaration : l'image du moteur tant que c'est sa marque, sinon rien.
     */
    private function imageUrl(string $key, string $engineAsset): ?string
    {
        $file = $this->config()[$key] ?? null;
        if (null === $file) {
            return $this->isDefault() ? $this->assets->getUrl($engineAsset) : null;
        }
        if (!\is_string($file) || '' === trim($file) || basename($file) !== $file) {
            throw $this->error(sprintf('« %s » doit être le nom d\'un fichier du dossier de marque (sans barre oblique).', $key));
        }
        $path = $this->directory.'/'.$file;
        if (!is_file($path)) {
            throw $this->error(sprintf('« %s » : fichier introuvable (%s).', $key, $path));
        }

        return $this->urls->generate('app_brand_image', ['role' => $key, 'v' => (string) (filemtime($path) ?: 0)]);
    }

    /** Le chemin du fichier d'une image déclarée, pour le contrôleur qui la sert. */
    public function imagePath(string $role): ?string
    {
        if (!\in_array($role, self::IMAGES, true)) {
            return null;
        }
        $file = $this->config()[$role] ?? null;
        if (!\is_string($file) || basename($file) !== $file) {
            return null;
        }
        $path = $this->directory.'/'.$file;

        return is_file($path) ? $path : null;
    }

    /**
     * Les valeurs déclarées d'un bloc (couleurs, polices), vérifiées, par variable CSS.
     *
     * @param array<string, string> $allowed clé du fichier => variable CSS
     *
     * @return array<string, string>
     */
    private function map(string $block, array $allowed): array
    {
        $values = $this->config()[$block] ?? [];
        if (!\is_array($values)) {
            throw $this->error(sprintf('« %s » doit être une liste de valeurs (%s).', $block, implode(', ', array_keys($allowed))));
        }
        $resolved = [];
        foreach ($values as $key => $value) {
            if (!\is_string($key) || !isset($allowed[$key])) {
                throw $this->error(sprintf('« %s.%s » inconnue (acceptées : %s).', $block, \is_string($key) ? $key : '?', implode(', ', array_keys($allowed))));
            }
            $value = \is_string($value) ? trim($value) : '';
            $valid = 'fonts' !== $block
                ? 1 === preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value)
                : 1 === preg_match('/^[a-z0-9 ,.\'"-]+$/i', $value);
            if (!$valid) {
                throw $this->error('fonts' === $block
                    ? sprintf('« fonts.%s » : une pile de polices est attendue (\'Newsreader\', Georgia, serif).', $key)
                    : sprintf('« %s.%s » : une couleur hexadécimale est attendue (#9a5b18).', $block, $key));
            }
            $resolved[$allowed[$key]] = $value;
        }

        return $resolved;
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        if (null !== $this->config) {
            return $this->config;
        }
        $path = $this->directory.'/'.self::FILE;
        if ('' === $this->directory || !is_file($path)) {
            return $this->config = [];
        }
        try {
            $parsed = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new \RuntimeException(sprintf('Marque : %s est illisible — %s', $path, $e->getMessage()), previous: $e);
        }
        if (!\is_array($parsed)) {
            throw new \RuntimeException(sprintf('Marque : %s doit décrire la marque (voir la documentation).', $path));
        }
        // Le fichier existe : c'est la marque de l'instance, même s'il ne redéclare qu'une clé.
        $this->config = $parsed;
        foreach (array_keys($this->config) as $key) {
            if (!\in_array($key, self::KEYS, true)) {
                throw $this->error(sprintf('clé « %s » inconnue (acceptées : %s).', $key, implode(', ', self::KEYS)));
            }
        }
        if (!\is_string($this->config['name'] ?? null) || '' === trim($this->config['name'])) {
            throw $this->error('« name » est obligatoire : c\'est le nom affiché partout.');
        }

        return $this->config;
    }

    private function error(string $message): \RuntimeException
    {
        return new \RuntimeException(sprintf('Marque (%s/%s) : %s', $this->directory, self::FILE, $message));
    }

    /**
     * Les textes de l'accueil du moteur : ils parlent de Forelse et de son premier parcours, donc ils
     * ne s'affichent que tant que c'est la marque de Forelse qui est servie.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function defaultHome(): array
    {
        return [
            'demo' => [
                'files' => ['MenuController.php', 'index.html.twig'],
                'code' => <<<'HTML'
                    <span class="cm">// Bruna veut une carte visible à l'adresse /menu</span>
                    <span class="at">#[Route(</span><span class="st">'/menu'</span><span class="at">, name: </span><span class="st">'app_menu'</span><span class="at">)]</span>
                    <span class="kw">public function</span> index(): Response
                    {
                        <span class="kw">return</span> $this->render(<span class="st">'menu/index.html.twig'</span>, [
                            <span class="st">'taverne'</span>    => <span class="st">'La Taverne du Dragon Ivre'</span>,
                            <span class="st">'platDuJour'</span> => <span class="st">'Ragoût de sanglier'</span>,
                        ]);
                    }<span class="cursor">▌</span>
                    HTML,
                'preview' => [
                    'url' => 'localhost/menu',
                    'name' => 'La Taverne du Dragon Ivre',
                    'label' => 'Plat du jour',
                    'value' => 'Ragoût de sanglier',
                    'row' => ['Hydromel de la maison', '3 po'],
                ],
                'goals' => ['La carte est accessible', 'Le plat du jour est affiché', 'Les prix sont en pièces d\'or'],
            ],
            'showcase' => [
                'eyebrow' => 'Le fil rouge',
                'title' => 'Chaque parcours a son fil rouge. Le premier : une taverne.',
                'lead' => [
                    'Pas de catalogue d\'exemples sans lien entre eux. Dans le parcours Symfony, Bruna vient de reprendre la Taverne du Dragon Ivre et n\'a jamais eu de site. La carte, les réservations, les employés, le livre d\'or : chaque chapitre répond à un besoin réel de l\'auberge.',
                    'Le code du premier exercice sert encore au dernier chapitre. Vous apprenez comme on développe pour de vrai : sur un projet qui grandit, qu\'on relit et qu\'on fait évoluer.',
                ],
                'sign' => [
                    'name' => "La Taverne du\nDragon Ivre",
                    'note' => 'Fondée au chapitre 1',
                    'alt' => 'Enseigne de la Taverne du Dragon Ivre, fil rouge du premier parcours',
                ],
            ],
            'author' => [
                'eyebrow' => 'Qui est derrière',
                'title' => 'Un développeur, pas une plateforme de cours en masse.',
                'lead' => 'Conçu par Arnaud Pointet, développeur indépendant (Forelse, Bordeaux), qui écrit chaque exercice comme il aurait voulu apprendre.',
            ],
        ];
    }
}
