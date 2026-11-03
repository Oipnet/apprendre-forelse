<?php

namespace App\Theme;

/**
 * Les variables CSS du thème, à poser après la feuille de styles. Vide quand rien n'est déclaré.
 *
 * Les valeurs ont été vérifiées au chargement (HexColor, FontStack) : rien de ce qui sort d'ici ne peut
 * refermer la balise <style>.
 */
final class ThemeStylesheet
{
    /**
     * Les couleurs réglables et le jeton du thème default qu'elles redéfinissent (voir playground/src/theme-default.css).
     * Le thème sombre des éditeurs n'en dépend pas : il reste celui du moteur.
     */
    public const array COLORS = [
        'accent' => '--color-accent',
        'accent-line' => '--color-accent-line',
        'gold' => '--color-highlight',
        'background' => '--color-bg',
        'background-2' => '--color-bg-2',
        'surface' => '--color-surface',
        'line' => '--color-line',
        'ink' => '--color-ink',
        'dark' => '--color-dark',
        'dark-ink' => '--color-dark-ink',
        'success' => '--color-success',
    ];

    /**
     * Les couleurs de l'éditeur d'exercice et de l'atelier (:root dans editor.css) : « editor » pour leur thème sombre,
     * « editor_light » pour leur thème clair, avec les mêmes clés. Déclarées à part du thème du site, et jamais
     * déduites l'une de l'autre : une couleur éclaircie ou assombrie automatiquement, c'est un contraste perdu au
     * hasard. Absentes, celles du moteur restent.
     */
    public const array EDITOR_COLORS = [
        'accent' => '--accent',
        'accent-fill' => '--accent-fill',
        'accent-text' => '--accent-text',
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

    public const array FONTS = ['serif' => '--font-serif', 'sans' => '--font-sans', 'mono' => '--font-mono'];

    public static function render(ThemeConfig $config): string
    {
        // Hors de toute couche : ces jetons l'emportent sur ceux de la feuille du thème (@layer theme), thème sombre compris.
        $css = self::rule('html', [...$config->colors, ...$config->fonts]);

        // Le thème sombre de l'éditeur d'exercice et de l'atelier : ses variables ne servent qu'à eux.
        $css .= self::rule(':root', $config->editor);

        // Son thème clair, quand le système le demande : même sélecteur que la palette claire du moteur (editor.css),
        // posé après elle, pour l'emporter.
        $light = self::rule(":root[data-editor-scheme='auto']", $config->editorLight);

        return '' === $light ? $css : $css.'@media (prefers-color-scheme: light){'.$light.'}';
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
