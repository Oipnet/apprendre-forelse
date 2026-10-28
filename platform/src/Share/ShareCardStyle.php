<?php

namespace App\Share;

use App\Theme\Theme;
use App\Theme\ThemeStylesheet;

/**
 * L'habillage des images de partage dessinées par le moteur (ShareImageRenderer) : ce que le thème déclare sous
 * « share_card », sinon les couleurs des emails et les polices du moteur. Le thème ne connaît pas le module Share :
 * c'est ici que l'un se traduit dans l'autre.
 */
final readonly class ShareCardStyle
{
    /** Les couleurs de l'éditeur stylisé des images de partage => celles de l'éditeur du thème (« editor »). */
    private const array EDITOR = ['background' => 'background', 'surface' => 'surface', 'line' => 'line', 'ink' => 'ink', 'muted' => 'muted', 'accent' => 'accent-text', 'gold' => 'gold', 'success' => 'success'];

    public function __construct(private Theme $theme)
    {
    }

    /**
     * Les chemins sont absolus.
     *
     * @return array{image: string|null, logo: string|null, name: string, font: string|null, text_font: string|null, background: string, ink: string, accent: string, muted: string, editor: array<string, string>}
     */
    public function style(): array
    {
        $config = $this->theme->config();
        $card = $config->shareCard;
        $email = $this->theme->emailColors();
        $path = static fn (?string $file) => null === $file ? null : $config->directory.'/'.$file;
        // L'éditeur stylisé de l'image : les couleurs de l'éditeur du thème, sinon celles du moteur.
        $editor = ShareImageRenderer::EDITOR;
        foreach ($editor as $name => $color) {
            $editor[$name] = $config->editor[ThemeStylesheet::EDITOR_COLORS[self::EDITOR[$name]]] ?? $color;
        }

        $logo = $this->theme->emailLogoRole();

        return [
            'image' => $path($card['image']),
            // Le logo des emails, en pied de l'image avec le nom du site.
            'logo' => null === $logo ? null : $path($config->images[$logo]),
            'name' => $config->name,
            'font' => $path($card['font']),
            // Le texte courant prend la police du titre quand le thème n'en déclare qu'une.
            'text_font' => $path($card['text_font'] ?? $card['font']),
            'background' => $card['colors']['background'] ?? $config->colors[ThemeStylesheet::COLORS['background']] ?? $email['background'],
            'ink' => $card['colors']['ink'] ?? $email['ink'],
            'accent' => $card['colors']['accent'] ?? $email['accent'],
            'muted' => $card['colors']['muted'] ?? $email['muted'],
            'editor' => $editor,
        ];
    }
}
