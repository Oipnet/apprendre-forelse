<?php

namespace App\Share;

/**
 * Dessine une image de partage (PNG, 1200 × 630, le format qu'attendent LinkedIn, X et Bluesky) : le fond du thème,
 * un surtitre de la couleur d'accent, le titre en grand, le site en pied.
 *
 * Le titre prend la plus grande taille qui le fait tenir en quatre lignes ; au-delà, il est coupé sur un mot.
 * Rien d'autre ne s'adapte : un thème qui fournit son fond lui laisse la place à gauche (voir « share_card » dans le README).
 */
final class ShareImageRenderer
{
    public const int WIDTH = 1200;
    public const int HEIGHT = 630;

    /** Les polices du moteur, quand le thème ne déclare pas les siennes. */
    public const string FONT = __DIR__.'/../../resources/fonts/DejaVuSans-Bold.ttf';
    public const string TEXT_FONT = __DIR__.'/../../resources/fonts/DejaVuSans.ttf';

    private const int MARGIN = 88;
    private const int MAX_LINES = 4;
    /** Tailles du titre essayées, en pixels, de la plus grande à la plus petite. */
    private const array TITLE_SIZES = [68, 62, 56, 50, 46];
    private const int EYEBROW_SIZE = 30;
    private const int FOOTER_SIZE = 26;

    /**
     * @param array{image: string|null, font: string|null, text_font: string|null, background: string, ink: string, accent: string, muted: string} $style voir Theme::shareCard()
     *
     * @return string le PNG
     */
    public function render(ShareCard $card, array $style): string
    {
        if (!\function_exists('imagettftext')) {
            throw new \LogicException('Les images de partage demandent l\'extension gd de PHP, avec FreeType.');
        }
        $font = $style['font'] ?? self::FONT;
        $textFont = $style['text_font'] ?? self::TEXT_FONT;

        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagefill($image, 0, 0, self::color($image, $style['background']));
        if (null !== $style['image']) {
            $this->cover($image, $style['image']);
        } else {
            // Sans fond fourni : un filet de la couleur d'accent, pour que l'image ne soit pas qu'un texte sur un aplat.
            imagefilledrectangle($image, 0, 0, 15, self::HEIGHT, self::color($image, $style['accent']));
        }

        $width = self::WIDTH - 2 * self::MARGIN;
        $y = self::MARGIN + self::EYEBROW_SIZE;
        if ('' !== $card->eyebrow) {
            self::text($image, self::EYEBROW_SIZE, $textFont, self::MARGIN, $y, $style['accent'], self::fit($card->eyebrow, self::EYEBROW_SIZE, $textFont, $width));
        }

        [$size, $lines] = self::wrapTitle($card->title, $font, $width);
        $lineHeight = (int) round($size * 1.22);
        $y += 48;
        foreach ($lines as $line) {
            $y += $lineHeight;
            self::text($image, $size, $font, self::MARGIN, $y, $style['ink'], $line);
        }

        if ('' !== $card->footer) {
            self::text($image, self::FOOTER_SIZE, $textFont, self::MARGIN, self::HEIGHT - self::MARGIN + 10, $style['muted'], self::fit($card->footer, self::FOOTER_SIZE, $textFont, $width));
        }

        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }

    /**
     * La taille du titre et ses lignes : la plus grande taille où il tient en quatre lignes, sinon la plus petite,
     * coupée après la quatrième ligne.
     *
     * @return array{int, list<string>}
     */
    private static function wrapTitle(string $title, string $font, int $width): array
    {
        foreach (self::TITLE_SIZES as $size) {
            $lines = self::wrap($title, $size, $font, $width);
            if (\count($lines) <= self::MAX_LINES) {
                return [$size, $lines];
            }
        }
        $lines = \array_slice($lines, 0, self::MAX_LINES);
        $lines[self::MAX_LINES - 1] = self::fit($lines[self::MAX_LINES - 1].' …', $size, $font, $width, true);

        return [$size, $lines];
    }

    /** @return list<string> le texte coupé sur les espaces, chaque ligne tenant dans la largeur */
    private static function wrap(string $text, int $size, string $font, int $width): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $candidate = '' === $line ? $word : $line.' '.$word;
            if ('' === $line || self::width($candidate, $size, $font) <= $width) {
                $line = $candidate;
                continue;
            }
            $lines[] = $line;
            $line = $word;
        }
        if ('' !== $line) {
            $lines[] = $line;
        }

        // Un mot plus long que la ligne est raccourci plutôt que de déborder de l'image.
        return array_map(static fn (string $l) => self::fit($l, $size, $font, $width), $lines);
    }

    /** Le texte tel quel s'il tient, sinon raccourci avec des points de suspension. */
    private static function fit(string $text, int $size, string $font, int $width, bool $ellipsis = false): string
    {
        if (!$ellipsis && self::width($text, $size, $font) <= $width) {
            return $text;
        }
        $text = (string) preg_replace('/[\s…]+$/u', '', $text);
        while ('' !== $text && self::width($text.'…', $size, $font) > $width) {
            $text = rtrim(mb_substr($text, 0, -1));
        }

        return $text.'…';
    }

    private static function width(string $text, int $size, string $font): int
    {
        $box = imagettfbbox(self::points($size), 0, $font, self::literal($text));

        return false === $box ? 0 : $box[2] - $box[0];
    }

    private static function text(\GdImage $image, int $size, string $font, int $x, int $y, string $hex, string $text): void
    {
        imagettftext($image, self::points($size), 0, $x, $y, self::color($image, $hex), $font, self::literal($text));
    }

    /** GD lit les entités HTML (« &amp; » dessiné « & ») : un titre qui en parle doit s'écrire tel quel. */
    private static function literal(string $text): string
    {
        return str_replace('&', '&#38;', $text);
    }

    /** GD compte en points, à 96 points par pouce : une taille en pixels en vaut les trois quarts. */
    private static function points(int $pixels): float
    {
        return $pixels * 0.75;
    }

    /** Le fond du thème, mis à l'échelle pour couvrir l'image et centré (le surplus est rogné). */
    private function cover(\GdImage $image, string $path): void
    {
        $source = @imagecreatefromstring((string) file_get_contents($path));
        if (false === $source) {
            return;
        }
        $scale = max(self::WIDTH / imagesx($source), self::HEIGHT / imagesy($source));
        $width = (int) ceil(self::WIDTH / $scale);
        $height = (int) ceil(self::HEIGHT / $scale);
        imagecopyresampled($image, $source, 0, 0, intdiv(imagesx($source) - $width, 2), intdiv(imagesy($source) - $height, 2), self::WIDTH, self::HEIGHT, $width, $height);
    }

    /** Une couleur #rgb, #rrggbb ou #rrggbbaa (l'opacité est ignorée). */
    private static function color(\GdImage $image, string $hex): int
    {
        $hex = ltrim($hex, '#');
        if (3 === \strlen($hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return (int) imagecolorallocate($image, (int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2)));
    }
}
