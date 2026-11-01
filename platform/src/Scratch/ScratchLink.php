<?php

namespace App\Scratch;

/**
 * Le lien partagé du bac à sable, côté serveur : le même format que la page (playground/src/app/scratch.ts), pour que
 * le serveur MCP fabrique et relise des liens sans rien stocker.
 *
 * Après « #code= » : le JSON {v: 1, files, packages?} compressé en deflate brut, puis encodé en base64url sans « = ».
 * Mêmes plafonds que la page : un lien que la page refuserait n'est ni fabriqué ni lu ici.
 */
final class ScratchLink
{
    public const string PREFIX = 'code=';
    /** Un fragment plus long n'est pas lu par la page (MAX_FRAGMENT). */
    public const int MAX_FRAGMENT = 200_000;
    /** Le code décompressé, au plus (MAX_BYTES). */
    public const int MAX_BYTES = 1_000_000;
    /** Fichiers et paquets, au plus (MAX_FILES). */
    public const int MAX_FILES = 100;

    /**
     * Le fragment (sans « # ») qui porte ces fichiers, et ces paquets s'il y en a.
     *
     * @param array<string, string> $files
     * @param list<string>          $packages
     *
     * @throws \LengthException si la page ne pourrait pas le lire
     */
    public function encode(array $files, array $packages = []): string
    {
        if (\count($files) > self::MAX_FILES || \count($packages) > self::MAX_FILES) {
            throw new \LengthException(\sprintf('A shared project holds at most %d files.', self::MAX_FILES));
        }
        $data = ['v' => 1, 'files' => (object) $files];
        if ([] !== $packages) {
            $data['packages'] = $packages;
        }
        $json = json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (\strlen($json) > self::MAX_BYTES) {
            throw new \LengthException('The project is too large to fit in a link.');
        }
        $fragment = self::PREFIX.rtrim(strtr(base64_encode((string) gzdeflate($json, 9)), '+/', '-_'), '=');
        if (\strlen($fragment) > self::MAX_FRAGMENT) {
            throw new \LengthException('The project is too large to fit in a link.');
        }

        return $fragment;
    }

    /**
     * Le projet porté par un fragment (ou une adresse entière), comme la page le lirait ; null s'il n'en porte pas ou
     * s'il est illisible. Un chemin qui remonte (`..`) ou part de la racine est écarté.
     *
     * @return array{files: array<string, string>, packages: list<string>}|null
     */
    public function decode(string $fragment): ?array
    {
        $hash = strpos($fragment, '#');
        $fragment = false === $hash ? $fragment : substr($fragment, $hash + 1);
        if (!str_starts_with($fragment, self::PREFIX) || \strlen($fragment) > self::MAX_FRAGMENT) {
            return null;
        }
        $bytes = base64_decode(strtr(substr($fragment, \strlen(self::PREFIX)), '-_', '+/'), true);
        // gzinflate s'arrête au plafond (false) : une adresse piégée ne fait pas gonfler la mémoire.
        $json = false === $bytes ? false : @gzinflate($bytes, self::MAX_BYTES);
        if (false === $json) {
            return null;
        }
        try {
            $data = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($data) || 1 !== ($data['v'] ?? null) || !\is_array($data['files'] ?? null)) {
            return null;
        }
        $files = [];
        foreach (\array_slice($data['files'], 0, self::MAX_FILES, true) as $path => $content) {
            $path = (string) $path;
            if (!\is_string($content) || str_starts_with($path, '/') || \in_array('', explode('/', $path), true) || \in_array('..', explode('/', $path), true)) {
                continue;
            }
            $files[$path] = $content;
        }
        $packages = \is_array($data['packages'] ?? null) ? array_values(array_filter($data['packages'], is_string(...))) : [];

        return ['files' => $files, 'packages' => \array_slice($packages, 0, self::MAX_FILES)];
    }
}
