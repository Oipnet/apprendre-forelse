<?php

namespace App\Instance\Branding;

/**
 * Les variables CSS de la marque, à poser après la feuille de styles. Vide quand rien n'est déclaré.
 *
 * Les valeurs ont été vérifiées au chargement (HexColor, FontStack) : rien de ce qui sort d'ici ne peut
 * refermer la balise <style>.
 */
final class BrandingStylesheet
{
    /**
     * Les couleurs réglables et la variable CSS qu'elles écrivent (voir playground/src/site.css).
     * Le thème sombre des éditeurs n'en dépend pas : il reste celui du moteur.
     */
    public const array COLORS = [
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
    public const array EDITOR_COLORS = [
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

    public const array FONTS = ['serif' => '--lp-serif', 'sans' => '--lp-sans', 'mono' => '--lp-mono'];

    public static function render(BrandingConfig $config): string
    {
        $css = self::rule('body.site-page', [...$config->colors, ...$config->fonts]);
        // Le fond de la page est aussi posé sur <html> (pas d'éclair blanc au chargement) : il suit.
        if (null !== ($background = $config->colors['--lp-bg'] ?? null)) {
            $css .= 'html:has(> body.site-page){background:'.$background.'}';
        }

        // Le thème sombre, que les pages du site redéfinissent pour elles : seuls l'éditeur et l'atelier le portent.
        return $css.self::rule(':root', $config->editor);
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
}
