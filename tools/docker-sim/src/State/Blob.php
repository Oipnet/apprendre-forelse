<?php

declare(strict_types=1);

namespace Forelse\DockerSim\State;

/**
 * Contenu d'un fichier d'image tel qu'il est rangé dans l'état JSON : du texte tel quel, du binaire
 * en base64 (préfixe « b64: »), ou une référence vers un fichier de l'hôte (« ref: ») pour les gros
 * fichiers copiés depuis le contexte (vendor/…), qui ne changent pas pendant une session.
 */
final class Blob
{
    /** Au-delà, le contenu est rangé à part plutôt que dans state.json. */
    private const INLINE_LIMIT = 2_000;

    /** Dossier des contenus rangés à part (state/blobs), posé par le Store. */
    private static ?string $directory = null;

    public static function useDirectory(string $directory): void
    {
        self::$directory = $directory;
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }
    }

    /**
     * Copie d'un fichier de l'hôte dans une image : au-delà de quelques kilo-octets, le contenu est
     * recopié une fois dans le dépôt de contenus (adressé par empreinte) et l'état ne garde qu'un
     * renvoi. L'image reste figée : modifier le fichier sur l'hôte ensuite ne la change pas.
     */
    public static function fromHostFile(string $hostPath): string
    {
        $size = (int) @filesize($hostPath);
        if ($size <= self::INLINE_LIMIT || self::$directory === null) {
            return self::encode((string) @file_get_contents($hostPath));
        }
        $hash = @hash_file('xxh128', $hostPath) ?: hash('xxh128', $hostPath);
        $stored = self::$directory.'/'.$hash;
        if (!is_file($stored)) {
            @copy($hostPath, $stored);
        }

        return 'blob:'.$size.':'.$hash;
    }

    /** Contenu déjà en mémoire, rangé de la même façon s'il est volumineux. */
    public static function store(string $content): string
    {
        if (\strlen($content) <= self::INLINE_LIMIT || self::$directory === null) {
            return self::encode($content);
        }
        $hash = hash('xxh128', $content);
        $stored = self::$directory.'/'.$hash;
        if (!is_file($stored)) {
            file_put_contents($stored, $content);
        }

        return 'blob:'.\strlen($content).':'.$hash;
    }

    private static function blobPath(string $blob): string
    {
        return (self::$directory ?? sys_get_temp_dir()).'/'.explode(':', $blob, 3)[2];
    }

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
            str_starts_with($blob, 'blob:') => (string) @file_get_contents(self::blobPath($blob)),
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

    /** Écrit le contenu sur disque ; une référence est copiée depuis l'hôte. */
    public static function write(string $blob, string $target): void
    {
        $dir = \dirname($target);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (is_link($target) || is_file($target)) {
            @unlink($target);
        }
        if (str_starts_with($blob, 'ref:')) {
            @copy(self::refPath($blob), $target);

            return;
        }
        if (str_starts_with($blob, 'blob:')) {
            @copy(self::blobPath($blob), $target);

            return;
        }
        if (str_starts_with($blob, 'virt:')) {
            // Binaire ou paquet simulé : un fichier vide suffit pour ls, test -f, which.
            touch($target);

            return;
        }
        file_put_contents($target, self::decode($blob));
    }

    private static function refPath(string $blob): string
    {
        return explode(':', $blob, 3)[2];
    }
}
