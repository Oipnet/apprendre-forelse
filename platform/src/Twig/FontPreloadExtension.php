<?php

namespace App\Twig;

use App\Theme\Theme;
use Pentatrion\ViteBundle\Service\FileAccessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `{% for url in font_preloads() %}` : les polices du haut de page, à précharger. Sans cela, le navigateur ne les
 * découvre qu'une fois la feuille de styles analysée : le titre s'affiche tard, puis change de police.
 *
 * Leurs URL (empreinte comprise) viennent du manifeste Vite. Sans manifeste (serveur de développement), rien.
 * Une police que le thème de l'instance remplace n'est pas préchargée : elle ne servirait pas. Un thème qui remplace
 * toute la feuille du moteur (replaces_engine_styles) n'en utilise aucune : il précharge les siennes (preload).
 */
final class FontPreloadExtension extends AbstractExtension implements ResetInterface
{
    /** Les polices visibles au-dessus de la ligne de flottaison (titres, texte courant), et la variable CSS qui les désigne. */
    public const array FONTS = [
        '--lp-serif' => 'src/fonts/newsreader-latin.woff2',
        '--lp-sans' => 'src/fonts/instrument-sans-latin.woff2',
    ];

    /** @var list<string>|null */
    private ?array $urls = null;

    /** @param array<string, array{base: string}> $configs */
    public function __construct(
        #[Autowire(service: 'pentatrion_vite.file_accessor')]
        private readonly FileAccessor $files,
        #[Autowire(param: 'pentatrion_vite.default_config')]
        private readonly string $config,
        #[Autowire(param: 'pentatrion_vite.configs')]
        private readonly array $configs,
        private readonly Theme $theme,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('font_preloads', $this->urls(...))];
    }

    /** @return list<string> */
    public function urls(): array
    {
        if (null !== $this->urls) {
            return $this->urls;
        }
        if ($this->theme->replacesEngineStyles() || !$this->files->hasFile($this->config, FileAccessor::MANIFEST)) {
            return $this->urls = [];
        }
        $manifest = $this->files->getData($this->config, FileAccessor::MANIFEST);
        $replaced = $this->theme->config()->fonts;

        $urls = [];
        foreach (self::FONTS as $variable => $source) {
            if (!isset($replaced[$variable]) && isset($manifest[$source])) {
                $urls[] = $this->configs[$this->config]['base'].$manifest[$source]['file'];
            }
        }

        return $this->urls = $urls;
    }

    /** Recalculées à la requête suivante : elles dépendent des polices du thème, relu lui aussi (Theme). */
    public function reset(): void
    {
        $this->urls = null;
    }
}
