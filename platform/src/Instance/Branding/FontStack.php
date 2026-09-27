<?php

namespace App\Instance\Branding;

final readonly class FontStack implements BlockValue
{
    public function accepts(string $value): bool
    {
        return 1 === preg_match('/^[a-z0-9 ,.\'"-]+$/i', $value);
    }

    public function expected(string $path): string
    {
        return sprintf('« %s » : une pile de polices est attendue (\'Newsreader\', Georgia, serif).', $path);
    }
}
