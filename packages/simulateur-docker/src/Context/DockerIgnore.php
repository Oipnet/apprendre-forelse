<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Context;

/**
 * Motifs d'un .dockerignore, avec la sémantique de Docker (Go filepath.Match + « ** » et « ! »).
 * Un chemin exclu par un motif de dossier exclut tout son contenu.
 */
final class DockerIgnore
{
    /** @var list<array{bool, string}> [négation, regex] dans l'ordre du fichier */
    private array $rules = [];

    public function __construct(string $content = '')
    {
        foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $negate = str_starts_with($line, '!');
            $pattern = trim($negate ? substr($line, 1) : $line, '/');
            if ($pattern === '') {
                continue;
            }
            $this->rules[] = [$negate, $this->toRegex($pattern)];
        }
    }

    public static function fromFile(string $path): self
    {
        return new self(is_file($path) ? (string) file_get_contents($path) : '');
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    /** Le chemin (relatif au contexte, sans « ./ ») est-il exclu ? */
    public function ignores(string $path): bool
    {
        $path = trim($path, '/');
        $ignored = false;
        foreach ($this->rules as [$negate, $regex]) {
            if ($this->matches($regex, $path)) {
                $ignored = !$negate;
            }
        }

        return $ignored;
    }

    /** Un motif s'applique au chemin lui-même ou à l'un de ses dossiers parents (« vendor » exclut « vendor/a/b »). */
    private function matches(string $regex, string $path): bool
    {
        $segments = explode('/', $path);
        for ($i = 1; $i <= \count($segments); ++$i) {
            if (preg_match($regex, implode('/', \array_slice($segments, 0, $i)))) {
                return true;
            }
        }

        return false;
    }

    private function toRegex(string $pattern): string
    {
        $regex = '';
        $length = \strlen($pattern);
        for ($i = 0; $i < $length; ++$i) {
            $char = $pattern[$i];
            if ($char === '*') {
                if ($i + 1 < $length && $pattern[$i + 1] === '*') {
                    ++$i;
                    // « **/ » : zéro ou plusieurs dossiers ; « ** » final : tout ce qui suit.
                    if ($i + 1 < $length && $pattern[$i + 1] === '/') {
                        ++$i;
                        $regex .= '(?:.*/)?';
                    } else {
                        $regex .= '.*';
                    }
                } else {
                    $regex .= '[^/]*';
                }
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } elseif ($char === '[') {
                $end = strpos($pattern, ']', $i);
                if ($end === false) {
                    $regex .= '\[';
                } else {
                    $regex .= '['.str_replace('\\', '\\\\', substr($pattern, $i + 1, $end - $i - 1)).']';
                    $i = $end;
                }
            } else {
                $regex .= preg_quote($char, '#');
            }
        }

        return '#^'.$regex.'$#';
    }
}
