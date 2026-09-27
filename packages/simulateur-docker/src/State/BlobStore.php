<?php

declare(strict_types=1);

namespace Forelse\DockerSim\State;

/**
 * Le dépôt des contenus volumineux (state/blobs), adressés par empreinte : state.json n'en garde
 * qu'un renvoi « blob:<taille>:<empreinte> ». Possédé par le Store, prêté aux MemoryFs d'un build.
 */
final class BlobStore
{
    public function __construct(public readonly string $directory)
    {
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }
    }

    /**
     * Copie d'un fichier de l'hôte dans une image : au-delà de quelques kilo-octets, le contenu est
     * recopié une fois dans le dépôt de contenus (adressé par empreinte) et l'état ne garde qu'un
     * renvoi. L'image reste figée : modifier le fichier sur l'hôte ensuite ne la change pas.
     */
    public function fromHostFile(string $hostPath): string
    {
        $size = (int) @filesize($hostPath);
        if ($size <= Blob::INLINE_LIMIT) {
            return Blob::encode((string) @file_get_contents($hostPath));
        }
        $hash = @hash_file('xxh128', $hostPath) ?: hash('xxh128', $hostPath);
        $stored = $this->directory.'/'.$hash;
        if (!is_file($stored)) {
            @copy($hostPath, $stored);
        }

        return 'blob:'.$size.':'.$hash;
    }

    /** Contenu déjà en mémoire, rangé de la même façon s'il est volumineux. */
    public function store(string $content): string
    {
        if (\strlen($content) <= Blob::INLINE_LIMIT) {
            return Blob::encode($content);
        }
        $hash = hash('xxh128', $content);
        $stored = $this->directory.'/'.$hash;
        if (!is_file($stored)) {
            file_put_contents($stored, $content);
        }

        return 'blob:'.\strlen($content).':'.$hash;
    }

    public function decode(string $blob): string
    {
        return str_starts_with($blob, 'blob:') ? (string) @file_get_contents($this->path($blob)) : Blob::decode($blob);
    }

    /** Écrit le contenu sur disque ; une référence est copiée depuis l'hôte. */
    public function write(string $blob, string $target): void
    {
        $dir = \dirname($target);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (is_link($target) || is_file($target)) {
            @unlink($target);
        }
        if (str_starts_with($blob, 'ref:')) {
            @copy(Blob::refPath($blob), $target);

            return;
        }
        if (str_starts_with($blob, 'blob:')) {
            @copy($this->path($blob), $target);

            return;
        }
        if (Blob::isVirtual($blob)) {
            // Binaire ou paquet simulé : un fichier vide suffit pour ls, test -f, which.
            touch($target);

            return;
        }
        file_put_contents($target, Blob::decode($blob));
    }

    private function path(string $blob): string
    {
        return $this->directory.'/'.explode(':', $blob, 3)[2];
    }
}
