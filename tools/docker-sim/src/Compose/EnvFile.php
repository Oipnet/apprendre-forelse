<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Compose;

/** Fichier .env (celui du projet pour l'interpolation, ou un env_file de service). */
final class EnvFile
{
    /** @return array<string,string> */
    public static function parse(string $content): array
    {
        $values = [];
        foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $line = preg_replace('/^export\s+/', '', $line) ?? $line;
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (\strlen($value) >= 2 && ($value[0] === '"' && str_ends_with($value, '"'))) {
                $value = stripcslashes(substr($value, 1, -1));
            } elseif (\strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
                $value = substr($value, 1, -1);
            } else {
                $value = trim(preg_replace('/\s+#.*$/', '', $value) ?? $value);
            }
            $values[$key] = $value;
        }

        return $values;
    }

    /** @return array<string,string> */
    public static function load(string $path): array
    {
        return is_file($path) ? self::parse((string) file_get_contents($path)) : [];
    }
}
