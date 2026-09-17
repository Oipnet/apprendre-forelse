<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Fs;

final class Path
{
    /** Normalise un chemin absolu : « /a/./b/../c/ » => « /a/c ». */
    public static function normalize(string $path, string $cwd = '/'): string
    {
        if ($path === '' || $path[0] !== '/') {
            $path = rtrim($cwd, '/').'/'.$path;
        }
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return '/'.implode('/', $parts);
    }

    public static function join(string ...$parts): string
    {
        return self::normalize(implode('/', $parts));
    }

    public static function isUnder(string $path, string $dir): bool
    {
        $dir = rtrim($dir, '/');

        return $dir === '' || $path === $dir || str_starts_with($path, $dir.'/');
    }

    /** Motif glob du shell (*, ?, [abc]) en expression régulière, sur un segment. */
    public static function globRegex(string $pattern): string
    {
        $regex = '';
        $length = \strlen($pattern);
        for ($i = 0; $i < $length; ++$i) {
            $c = $pattern[$i];
            $regex .= match ($c) {
                '*' => '[^/]*',
                '?' => '[^/]',
                default => preg_quote($c, '#'),
            };
            if ($c === '[' ) {
                $end = strpos($pattern, ']', $i);
                if ($end !== false) {
                    $regex = substr($regex, 0, -2).'['.substr($pattern, $i + 1, $end - $i - 1).']';
                    $i = $end;
                }
            }
        }

        return '#^'.$regex.'$#';
    }

    public static function hasGlob(string $word): bool
    {
        return strpbrk($word, '*?[') !== false;
    }
}
