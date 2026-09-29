<?php

namespace App\Content;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Les fichiers d'où vient le contenu : lus (YAML des packs), et notés au passage, pour que PackLoader sache quand son
 * cache est périmé.
 */
final class PackFiles
{
    /** @var array<string, string> empreinte (date, taille) de chaque fichier ou dossier lu, par chemin */
    private array $watched = [];

    /** @return array<string, string> */
    public function watched(): array
    {
        return $this->watched;
    }

    /**
     * Note un fichier ou un dossier dont dépend le contenu chargé. Un fichier modifié change de date ou de taille ; un
     * fichier ajouté ou retiré change la date de son dossier. Un chemin absent (une fiche lesson.md facultative) est
     * remplacé par son plus proche parent existant : le créer changera la date de celui-ci.
     */
    public function watch(string $path): void
    {
        while (!file_exists($path) && \dirname($path) !== $path) {
            $path = \dirname($path);
        }
        $this->watched[$path] = self::fingerprint($path);
    }

    public static function fingerprint(string $path): string
    {
        $stat = @stat($path);

        return false === $stat ? '' : $stat['mtime'].'/'.$stat['size'];
    }

    /** @return array<string, mixed> */
    public function parse(string $file): array
    {
        $this->watch($file);
        if (!is_file($file)) {
            throw new ContentException(sprintf('Fichier manquant : %s', $file));
        }
        try {
            $data = Yaml::parseFile($file);
        } catch (ParseException $e) {
            // Une erreur de format comme une autre : signalée avec le fichier et la ligne, jamais une page 500.
            throw new ContentException(sprintf('%s : YAML invalide, %s', $file, lcfirst($e->getMessage())), previous: $e);
        }
        if (!\is_array($data)) {
            throw new ContentException(sprintf('%s : contenu YAML invalide.', $file));
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public function required(array $data, string $key, string $file): string
    {
        if (!isset($data[$key]) || '' === $data[$key]) {
            throw new ContentException(sprintf('%s : clé « %s » manquante.', $file, $key));
        }
        if (!\is_scalar($data[$key])) {
            throw new ContentException(sprintf('%s : « %s » est un texte, pas une liste ni un objet.', $file, $key));
        }

        return (string) $data[$key];
    }
}
