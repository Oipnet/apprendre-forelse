<?php

namespace App\Content\Author;

use App\Content\ContentException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Lecture et écriture des fichiers d'un exercice, dans son dossier et nulle part ailleurs.
 *
 * Les chemins viennent d'un formulaire : ils sont vérifiés un par un (pas de « .. », pas de
 * chemin absolu, et seulement les emplacements que le format prévoit).
 */
final class ExerciseFiles
{
    /** Fichiers autorisés à la racine de l'exercice. */
    public const array ROOT_FILES = ['exercise.yaml', 'instructions.md'];
    /** Dossiers autorisés. */
    public const array DIRECTORIES = ['starter', 'solution', 'tests'];

    private readonly Filesystem $filesystem;

    public function __construct()
    {
        $this->filesystem = new Filesystem();
    }

    /**
     * Tous les fichiers de l'exercice, par chemin relatif (exercise.yaml, starter/src/…).
     *
     * @return array<string, string>
     */
    public function read(string $directory): array
    {
        $fichiers = [];
        foreach (self::ROOT_FILES as $nom) {
            if (is_file($directory.'/'.$nom)) {
                $fichiers[$nom] = (string) file_get_contents($directory.'/'.$nom);
            }
        }
        foreach (self::DIRECTORIES as $dossier) {
            if (!is_dir($directory.'/'.$dossier)) {
                continue;
            }
            foreach ((new Finder())->files()->in($directory.'/'.$dossier)->ignoreDotFiles(false)->sortByName() as $fichier) {
                $fichiers[$dossier.'/'.str_replace('\\', '/', $fichier->getRelativePathname())] = $fichier->getContents();
            }
        }
        ksort($fichiers);

        return $fichiers;
    }

    /**
     * Écrit les fichiers donnés et supprime ceux qui ont disparu.
     *
     * @param array<string, string> $fichiers contenu par chemin relatif
     */
    public function write(string $directory, array $fichiers): void
    {
        foreach (array_keys($fichiers) as $chemin) {
            $this->assertChemin($chemin);
        }
        if (!$fichiers) {
            throw new ContentException('Un exercice ne peut pas être vide.');
        }

        foreach ($fichiers as $chemin => $contenu) {
            $this->filesystem->dumpFile($directory.'/'.$chemin, $contenu);
        }
        foreach (array_keys($this->read($directory)) as $existant) {
            if (!\array_key_exists($existant, $fichiers)) {
                $this->filesystem->remove($directory.'/'.$existant);
            }
        }
        $this->supprimerLesDossiersVides($directory);
    }

    /** Le chemin est-il acceptable pour un fichier d'exercice ? */
    public function assertChemin(string $chemin): void
    {
        $invalide = match (true) {
            '' === trim($chemin) => 'un chemin vide',
            str_starts_with($chemin, '/') => 'un chemin absolu',
            str_contains($chemin, '\\') => 'un antislash',
            (bool) preg_match('#(^|/)\.\.?(/|$)#', $chemin) => '« . » ou « .. »',
            !preg_match('#^[A-Za-z0-9._/-]+$#', $chemin) => 'des caractères inattendus',
            \in_array($chemin, self::ROOT_FILES, true) => null,
            !\in_array(explode('/', $chemin)[0], self::DIRECTORIES, true) => sprintf('un emplacement inconnu (attendu : %s ou %s)', implode(', ', self::ROOT_FILES), implode('/, ', self::DIRECTORIES).'/'),
            1 === \count(explode('/', $chemin)) => 'un dossier sans fichier',
            default => null,
        };

        if (null !== $invalide) {
            throw new ContentException(sprintf('Chemin de fichier refusé (« %s ») : %s.', $chemin, $invalide));
        }
    }

    private function supprimerLesDossiersVides(string $directory): void
    {
        foreach (self::DIRECTORIES as $dossier) {
            if (!is_dir($directory.'/'.$dossier)) {
                continue;
            }
            $vides = (new Finder())->directories()->in($directory.'/'.$dossier)->sortByName()->reverseSorting();
            foreach ([...iterator_to_array($vides), new \SplFileInfo($directory.'/'.$dossier)] as $sousDossier) {
                if (is_dir($sousDossier->getPathname()) && !(new Finder())->files()->in($sousDossier->getPathname())->hasResults()) {
                    $this->filesystem->remove($sousDossier->getPathname());
                }
            }
        }
    }
}
