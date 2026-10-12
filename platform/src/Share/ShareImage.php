<?php

namespace App\Share;

use App\Theme\Theme;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Les images de partage dessinées par le moteur, gardées sur disque une fois faites.
 *
 * Une image est désignée par sa version : l'empreinte de ce qu'elle écrit et de l'habillage du thème. Un titre
 * corrigé, un thème changé donnent une autre version, donc une autre adresse : les réseaux, qui gardent un aperçu
 * longtemps, voient la nouvelle image.
 */
final readonly class ShareImage
{
    /** À changer quand le dessin change (ShareImageRenderer) : toutes les images sont alors refaites. */
    private const int DRAWING = 1;

    public function __construct(
        private Theme $theme,
        private ShareImageRenderer $renderer,
        #[Autowire('%kernel.cache_dir%/partage')]
        private string $directory,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /** L'empreinte courte de l'image, pour l'adresse (« ?v=… »). */
    public function version(ShareCard $card): string
    {
        $style = $this->theme->shareCard();
        $files = array_filter([$style['image'], $style['font'], $style['text_font']]);

        return substr(hash('xxh128', serialize([
            self::DRAWING,
            $card,
            $style,
            array_map(static fn (string $file) => @filemtime($file), $files),
        ])), 0, 16);
    }

    /** Le fichier de l'image, dessinée si elle ne l'est pas encore. */
    public function path(ShareCard $card): string
    {
        $path = $this->directory.'/'.$this->version($card).'.png';
        if (!is_file($path)) {
            // Écrite à côté puis renommée : deux requêtes simultanées ne servent jamais une image à moitié écrite.
            $this->filesystem->dumpFile($path, $this->renderer->render($card, $this->theme->shareCard()));
        }

        return $path;
    }
}
