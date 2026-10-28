<?php

namespace App\Theme;

/**
 * Le calcul des couleurs des emails, sans thème ni service : ce que le thème déclare, complété par les jetons clairs du
 * thème default, et un texte de bouton lisible sur l'accent (contraste WCAG).
 */
final class ThemeColors
{
    /** Les couleurs des emails (styles en ligne) quand le thème n'en déclare pas : les jetons clairs du thème default. */
    public const array EMAIL = [
        'accent' => '#1d4ed8',
        'ink' => '#1c1917',
        'muted' => '#57534e',
        'line' => '#d6d3d1',
        'background' => '#f5f5f4',
        'surface' => '#ffffff',
    ];

    /**
     * Les couleurs des emails, qui ne lisent pas de feuille de styles. Le texte du bouton suit le fond de l'accent :
     * blanc sur une couleur sombre, encre sur une couleur claire.
     *
     * @param array<string, string> $declared les couleurs du thème (`colors`), par variable CSS
     *
     * @return array{accent: string, onAccent: string, ink: string, muted: string, line: string, background: string, surface: string}
     */
    public static function email(array $declared): array
    {
        $colors = [
            'accent' => $declared[ThemeStylesheet::COLORS['accent']] ?? self::EMAIL['accent'],
            'ink' => $declared[ThemeStylesheet::COLORS['ink']] ?? self::EMAIL['ink'],
            'muted' => self::EMAIL['muted'],
            'line' => $declared[ThemeStylesheet::COLORS['line']] ?? self::EMAIL['line'],
            'background' => $declared[ThemeStylesheet::COLORS['background-2']] ?? $declared[ThemeStylesheet::COLORS['background']] ?? self::EMAIL['background'],
            'surface' => $declared[ThemeStylesheet::COLORS['surface']] ?? self::EMAIL['surface'],
        ];

        return ['onAccent' => self::luminance($colors['accent']) > 0.4 ? $colors['ink'] : '#ffffff'] + $colors;
    }

    /** La luminance relative (WCAG) d'une couleur #rgb, #rrggbb ou #rrggbbaa (l'opacité est ignorée). */
    public static function luminance(string $hex): float
    {
        if (4 === \strlen($hex)) {
            $hex = '#'.$hex[1].$hex[1].$hex[2].$hex[2].$hex[3].$hex[3];
        }
        $channels = array_map(static function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($hex, 1, 6), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
