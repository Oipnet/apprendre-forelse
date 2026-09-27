<?php

namespace App\Instance\Branding;

final readonly class HexColor implements BlockValue
{
    public function accepts(string $value): bool
    {
        return 1 === preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value);
    }

    public function expected(string $path): string
    {
        return sprintf('« %s » : une couleur hexadécimale est attendue (#9a5b18).', $path);
    }
}
