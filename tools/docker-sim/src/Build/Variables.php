<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Build;

/** Substitution des variables dans les instructions du Dockerfile ($VAR, ${VAR}, ${VAR:-défaut}, ${VAR:+alt}). */
final class Variables
{
    /**
     * @param array<string,string> $variables
     * @param list<string>         $undefined  noms utilisés sans être définis (avertissement UndefinedVar)
     */
    public static function expand(string $text, array $variables, array &$undefined = []): string
    {
        return (string) preg_replace_callback('/\\\\\$|\$\{([A-Za-z_][A-Za-z0-9_]*)(?:(:?[-+])([^}]*))?\}|\$([A-Za-z_][A-Za-z0-9_]*)/', static function (array $m) use ($variables, &$undefined): string {
            if ($m[0] === '\\$') {
                return '$';
            }
            $name = ($m[1] ?? '') !== '' ? $m[1] : ($m[4] ?? '');
            $op = $m[2] ?? '';
            $word = $m[3] ?? '';
            $set = \array_key_exists($name, $variables);
            $value = $variables[$name] ?? '';
            if (!$set && $op === '') {
                $undefined[] = $name;
            }

            return match ($op) {
                ':-' => $value === '' ? $word : $value,
                '-' => $set ? $value : $word,
                ':+' => $value !== '' ? $word : '',
                '+' => $set ? $word : '',
                default => $value,
            };
        }, $text);
    }

    /**
     * ENV / LABEL / ARG : « clé=valeur » (plusieurs, guillemets possibles) ou forme historique « clé valeur ».
     *
     * @return array{0: array<string,string>, 1: bool} paires, forme historique utilisée
     */
    public static function pairs(string $arguments): array
    {
        $arguments = trim($arguments);
        if (!preg_match('/^[^\s=]+=/', $arguments)) {
            $parts = preg_split('/\s+/', $arguments, 2) ?: [''];

            return [[$parts[0] => self::unquote($parts[1] ?? '')], true];
        }
        $pairs = [];
        preg_match_all('/([^\s=]+)=("(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|(?:\\\\\s|\S)*)/', $arguments, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $pairs[$match[1]] = self::unquote($match[2]);
        }

        return [$pairs, false];
    }

    public static function unquote(string $value): string
    {
        if (\strlen($value) >= 2 && ($value[0] === '"' && str_ends_with($value, '"'))) {
            return stripcslashes(substr($value, 1, -1));
        }
        if (\strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            return substr($value, 1, -1);
        }

        return str_replace('\\ ', ' ', $value);
    }
}
