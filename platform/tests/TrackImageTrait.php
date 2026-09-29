<?php

namespace App\Tests;

/** Des en-têtes PNG aux dimensions voulues : getimagesize() n'en lit pas plus, et les tests n'ont pas besoin de GD. */
trait TrackImageTrait
{
    protected static function pngHeader(int $width, int $height): string
    {
        $ihdr = 'IHDR'.pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));
    }
}
