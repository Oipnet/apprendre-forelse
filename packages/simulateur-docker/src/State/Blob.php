<?php

declare(strict_types=1);

namespace Forelse\DockerSim\State;

/**
 * Contenu d'un fichier d'image tel qu'il est rangé dans l'état JSON : du texte tel quel, du binaire
 * en base64 (préfixe « b64: »), ou une référence vers un fichier de l'hôte (« ref: ») pour les gros
 * fichiers copiés depuis le contexte (vendor/…), qui ne changent pas pendant une session.
 * Les contenus volumineux (« blob: ») vivent dans un dossier : c'est le BlobStore qui les range et les lit.
 */
final class Blob
{
    /** Au-delà, le contenu est rangé à part (BlobStore) plutôt que dans state.json. */
    public const INLINE_LIMIT = 2_000;

    /** Contenu fictif d'une taille donnée (paquets, index apt…) : compte dans la taille des couches, vide à la lecture. */
    public static function virtual(int $size): string
    {
        return 'virt:'.$size;
    }

    public static function isVirtual(string $blob): bool
    {
        return str_starts_with($blob, 'virt:');
    }

    public static function encode(string $content): string
    {
        if (str_starts_with($content, 'ref:') || str_starts_with($content, 'b64:') || str_starts_with($content, 'txt:') || str_starts_with($content, 'virt:') || str_starts_with($content, 'blob:') || !mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            return mb_check_encoding($content, 'UTF-8') && !str_contains($content, "\0") ? 'txt:'.$content : 'b64:'.base64_encode($content);
        }

        return $content;
    }

    public static function decode(string $blob): string
    {
        return match (true) {
            str_starts_with($blob, 'b64:') => (string) base64_decode(substr($blob, 4), true),
            str_starts_with($blob, 'txt:') => substr($blob, 4),
            str_starts_with($blob, 'ref:') => (string) @file_get_contents(self::refPath($blob)),
            str_starts_with($blob, 'blob:') => throw new \LogicException('Un contenu rangé à part se lit par son BlobStore.'),
            str_starts_with($blob, 'virt:') => '',
            default => $blob,
        };
    }

    public static function size(string $blob): int
    {
        return match (true) {
            str_starts_with($blob, 'b64:') => (int) (\strlen($blob) - 4) * 3 / 4,
            str_starts_with($blob, 'txt:') => \strlen($blob) - 4,
            str_starts_with($blob, 'ref:'), str_starts_with($blob, 'blob:') => (int) explode(':', $blob, 3)[1],
            str_starts_with($blob, 'virt:') => (int) substr($blob, 5),
            default => \strlen($blob),
        };
    }

    /** Chemin sur l'hôte d'une référence « ref: ». */
    public static function refPath(string $blob): string
    {
        return explode(':', $blob, 3)[2];
    }
}
