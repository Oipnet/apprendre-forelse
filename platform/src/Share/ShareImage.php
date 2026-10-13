<?php

namespace App\Share;

use App\Theme\Theme;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

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
    private const int DRAWING = 2;

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
            // Le contenu, pas la date : un redéploiement qui recopie les mêmes fichiers garde les mêmes adresses.
            array_map(static fn (string $file) => @hash_file('xxh128', $file), $files),
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

    /**
     * La réponse qui sert l'image : figée sous l'adresse qui porte sa version (« ?v= »), gardée une heure sinon
     * (un ancien aperçu, ou pas de version).
     */
    public function response(ShareCard $card, Request $request): BinaryFileResponse
    {
        $response = new BinaryFileResponse($this->path($card), headers: ['Content-Type' => 'image/png']);
        $response->setPublic();
        if ($request->query->get('v') === $this->version($card)) {
            $response->setMaxAge(31536000);
            $response->setImmutable();
        } else {
            $response->setMaxAge(3600);
        }

        return $response;
    }
}
