<?php

namespace App\Share;

/**
 * Dessine une image de partage (PNG, 1200 × 630, le format qu'attendent LinkedIn, X et Bluesky) : à gauche, le
 * surtitre dans une pastille de la couleur d'accent, le titre en grand et le site en pied ; à droite, un éditeur de
 * code stylisé aux couleurs de l'éditeur du thème, dont les lignes varient d'un titre à l'autre.
 *
 * Le titre prend la plus grande taille qui le fait tenir dans sa place, en cinq lignes au plus ; au-delà, il est coupé sur un mot. Un thème
 * qui fournit son fond n'a pas d'éditeur : le fond lui laisse la place à droite (voir « share_card » dans le README).
 * L'image est dessinée au double de sa taille puis réduite, pour lisser les bords arrondis.
 */
final class ShareImageRenderer
{
    public const int WIDTH = 1200;
    public const int HEIGHT = 630;

    /** Les polices du moteur, quand le thème ne déclare pas les siennes. */
    public const string FONT = __DIR__.'/../../resources/fonts/DejaVuSans-Bold.ttf';
    public const string TEXT_FONT = __DIR__.'/../../resources/fonts/DejaVuSans.ttf';

    /** Les couleurs de l'éditeur du moteur (playground/src/editor.css), quand le thème ne déclare pas les siennes. */
    public const array EDITOR = ['background' => '#18181b', 'surface' => '#1f1f23', 'line' => '#3f3f46', 'ink' => '#e4e4e7', 'muted' => '#a1a1aa', 'accent' => '#60a5fa', 'gold' => '#facc15', 'success' => '#4ade80'];

    /** Dessinée au double, puis réduite. */
    private const int SCALE = 2;
    private const int MARGIN = 80;
    /** La colonne du texte, à gauche de l'éditeur ; toute la largeur quand le thème fournit son fond. */
    private const int COLUMN = 600;
    private const int MAX_LINES = 5;
    /** Tailles du titre essayées, en pixels, de la plus grande à la plus petite. */
    private const array TITLE_SIZES = [60, 54, 50, 46, 42];
    private const int EYEBROW_SIZE = 24;
    private const int FOOTER_SIZE = 24;

    /**
     * @param array{image: string|null, logo?: string|null, name?: string, font: string|null, text_font: string|null, background: string, ink: string, accent: string, muted: string, editor?: array<string, string>} $style voir ShareCardStyle::style()
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

        $image = imagecreatetruecolor(self::WIDTH * self::SCALE, self::HEIGHT * self::SCALE);
        imagefill($image, 0, 0, self::color($image, $style['background']));
        if (null !== $style['image']) {
            $this->cover($image, $style['image']);
            $width = self::WIDTH - 2 * self::MARGIN;
        } else {
            self::editor($image, ($style['editor'] ?? []) + self::EDITOR, $card->title);
            $width = self::COLUMN - self::MARGIN;
        }

        $y = self::MARGIN;
        if ('' !== $card->eyebrow) {
            $y = self::pill($image, $card->eyebrow, $textFont, $y, $style['accent'], $style['background'], $width);
        }

        // Le titre centré dans la place qui reste entre le surtitre et le pied.
        $top = $y + 24;
        $bottom = self::HEIGHT - self::MARGIN - self::FOOTER_SIZE - 40;
        [$size, $lines] = self::wrapTitle($card->title, $font, $width, $bottom - $top);
        $lineHeight = self::lineHeight($size);
        $y = $top + intdiv($bottom - $top - $lineHeight * \count($lines), 2) - (int) round($size * 0.22);
        foreach ($lines as $line) {
            $y += $lineHeight;
            self::text($image, $size, $font, self::MARGIN, $y, $style['ink'], $line);
        }

        $logo = null === ($style['logo'] ?? null) ? false : @imagecreatefromstring((string) file_get_contents($style['logo']));
        if (false !== $logo) {
            self::footerWithLogo($image, $logo, $style['name'] ?? '', $card->footer, $font, $textFont, $style, $width);
        } elseif ('' !== $card->footer) {
            self::rectangle($image, self::MARGIN, self::HEIGHT - self::MARGIN - 34, self::MARGIN + 40, self::HEIGHT - self::MARGIN - 30, $style['accent']);
            self::text($image, self::FOOTER_SIZE, $textFont, self::MARGIN, self::HEIGHT - self::MARGIN + 4, $style['muted'], self::fit($card->footer, self::FOOTER_SIZE, $textFont, $width));
        }

        $final = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagecopyresampled($final, $image, 0, 0, 0, 0, self::WIDTH, self::HEIGHT, self::WIDTH * self::SCALE, self::HEIGHT * self::SCALE);
        ob_start();
        imagepng($final, null, 9);

        return (string) ob_get_clean();
    }

    /**
     * Le pied avec le logo du thème : le logo, le nom du site dans la police du titre, puis l'adresse.
     *
     * @param array{ink: string, muted: string} $style
     */
    private static function footerWithLogo(\GdImage $image, \GdImage $logo, string $name, string $footer, string $font, string $textFont, array $style, int $width): void
    {
        $height = 64;
        $logoWidth = (int) round(imagesx($logo) * $height / imagesy($logo));
        $top = self::HEIGHT - self::MARGIN - $height + 12;
        imagealphablending($image, true);
        imagecopyresampled($image, $logo, self::MARGIN * self::SCALE, $top * self::SCALE, 0, 0, $logoWidth * self::SCALE, $height * self::SCALE, imagesx($logo), imagesy($logo));

        $x = self::MARGIN + $logoWidth + 16;
        $baseline = $top + intdiv($height, 2) + 11;
        if ('' !== $name) {
            $name = self::fit($name, 32, $font, $width - $logoWidth - 16);
            self::text($image, 32, $font, $x, $baseline, $style['ink'], $name);
            $x += self::width($name, 32, $font) + 20;
        }
        if ('' !== $footer && $x < self::MARGIN + $width) {
            self::text($image, 22, $textFont, $x, $baseline - 1, $style['muted'], self::fit($footer, 22, $textFont, self::MARGIN + $width - $x));
        }
    }

    /** Le surtitre en pastille ; rend le bas de la pastille. */
    private static function pill(\GdImage $image, string $text, string $font, int $top, string $fill, string $ink, int $width): int
    {
        $padX = 18;
        $height = self::EYEBROW_SIZE + 22;
        $text = self::fit($text, self::EYEBROW_SIZE, $font, $width - 2 * $padX);
        self::rounded($image, self::MARGIN, $top, self::MARGIN + self::width($text, self::EYEBROW_SIZE, $font) + 2 * $padX, $top + $height, intdiv($height, 2), $fill);
        self::text($image, self::EYEBROW_SIZE, $font, self::MARGIN + $padX, $top + intdiv($height, 2) + (int) round(self::EYEBROW_SIZE * 0.36), $ink, $text);

        return $top + $height;
    }

    /**
     * Une fenêtre d'éditeur qui déborde à droite et en bas : barre de titre, numéros de ligne, du « code » en barres
     * colorées. Le dessin des lignes dépend du titre : deux exercices n'ont pas la même image.
     *
     * @param array<string, string> $colors
     */
    private static function editor(\GdImage $image, array $colors, string $seed): void
    {
        $left = 660;
        $top = 96;
        self::rounded($image, $left, $top, self::WIDTH + 40, self::HEIGHT + 40, 22, $colors['background']);
        self::rounded($image, $left, $top, self::WIDTH + 40, $top + 52, 22, $colors['surface']);
        self::rectangle($image, $left, $top + 30, self::WIDTH, $top + 52, $colors['surface']);
        foreach (['#ff5f57', '#febc2e', '#28c840'] as $i => $dot) {
            self::rounded($image, $left + 24 + 26 * $i, $top + 19, $left + 38 + 26 * $i, $top + 33, 7, $dot);
        }

        $random = crc32($seed);
        $next = static function (int $max) use (&$random): int {
            $random = ($random * 1103515245 + 12345) & 0x7FFFFFFF;

            return $random % $max;
        };
        $tokens = [$colors['accent'], $colors['ink'], $colors['gold'], $colors['success'], $colors['muted']];
        $indent = 0;
        for ($line = 0; $line < 11; ++$line) {
            $y = $top + 86 + 40 * $line;
            self::rounded($image, $left + 28, $y, $left + 28 + (\strlen((string) ($line + 1)) * 10), $y + 12, 3, $colors['line']);
            $x = $left + 76 + 32 * $indent;
            for ($token = 0, $count = 1 + $next(3); $token < $count; ++$token) {
                $length = 28 + $next(110);
                self::rounded($image, $x, $y, $x + $length, $y + 12, 6, $tokens[$next(\count($tokens))]);
                $x += $length + 14;
            }
            $indent = max(0, min(3, $indent + $next(3) - 1));
        }
    }

    /** Un rectangle aux coins arrondis, en coordonnées de l'image finale. */
    private static function rounded(\GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, string $hex): void
    {
        $s = self::SCALE;
        [$x1, $y1, $x2, $y2, $r] = [$x1 * $s, $y1 * $s, $x2 * $s, $y2 * $s, min($radius * $s, intdiv(($x2 - $x1) * $s, 2), intdiv(($y2 - $y1) * $s, 2))];
        $color = self::color($image, $hex);
        imagefilledrectangle($image, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($image, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($image, $cx, $cy, 2 * $r, 2 * $r, $color);
        }
    }

    private static function rectangle(\GdImage $image, int $x1, int $y1, int $x2, int $y2, string $hex): void
    {
        imagefilledrectangle($image, $x1 * self::SCALE, $y1 * self::SCALE, $x2 * self::SCALE, $y2 * self::SCALE, self::color($image, $hex));
    }

    /**
     * La taille du titre et ses lignes : la plus grande taille où il tient en largeur et en hauteur, sinon la plus
     * petite, coupée après la dernière ligne qui tient.
     *
     * @return array{int, list<string>}
     */
    private static function wrapTitle(string $title, string $font, int $width, int $height): array
    {
        foreach (self::TITLE_SIZES as $size) {
            $lines = self::wrap($title, $size, $font, $width);
            $max = min(self::MAX_LINES, intdiv($height, self::lineHeight($size)));
            if (\count($lines) <= $max) {
                return [$size, $lines];
            }
        }
        $lines = \array_slice($lines, 0, $max);
        $lines[$max - 1] = self::fit($lines[$max - 1].' …', $size, $font, $width, true);

        return [$size, $lines];
    }

    private static function lineHeight(int $size): int
    {
        return (int) round($size * 1.18);
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
        imagettftext($image, self::points($size * self::SCALE), 0, $x * self::SCALE, $y * self::SCALE, self::color($image, $hex), $font, self::literal($text));
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
        [$canvasWidth, $canvasHeight] = [imagesx($image), imagesy($image)];
        $scale = max($canvasWidth / imagesx($source), $canvasHeight / imagesy($source));
        $width = (int) ceil($canvasWidth / $scale);
        $height = (int) ceil($canvasHeight / $scale);
        imagecopyresampled($image, $source, 0, 0, intdiv(imagesx($source) - $width, 2), intdiv(imagesy($source) - $height, 2), $canvasWidth, $canvasHeight, $width, $height);
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
