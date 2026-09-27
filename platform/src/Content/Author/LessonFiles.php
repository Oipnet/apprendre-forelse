<?php

namespace App\Content\Author;

use App\Content\Chapter;
use App\Content\ContentException;
use App\Content\ContentRepository;
use App\Content\Track;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

/**
 * Écriture et retrait de la fiche de cours d'un chapitre (chapters/<chapitre>/lesson.md), sous un verrou
 * propre à la fiche : deux enregistrements simultanés passent l'un après l'autre.
 */
final class LessonFiles
{
    public function __construct(
        private readonly ContentRepository $content,
        private readonly Filesystem $filesystem,
        private readonly LockFactory $locks,
    ) {
    }

    public function chemin(Track $track, Chapter $chapter): string
    {
        return sprintf('%s/chapters/%s/lesson.md', $track->directory, $chapter->id);
    }

    /** Écrit la fiche dans le pack. Refuse d'écraser une fiche existante sans $force. */
    public function ecrire(Track $track, Chapter $chapter, string $markdown, bool $force = false): string
    {
        $chemin = $this->chemin($track, $chapter);
        $this->sousVerrou($chemin, function () use ($track, $chemin, $markdown, $force): void {
            if (is_file($chemin) && !$force) {
                throw new ContentException(sprintf('%s existe déjà : relisez-le, ou passez --force pour le remplacer.', $chemin));
            }
            $this->assertModifiable($track);
            $this->filesystem->dumpFile($chemin, $markdown);
        });
        $this->content->reset();

        return $chemin;
    }

    /** Retire la fiche du pack : le chapitre n'en a plus. */
    public function supprimer(Track $track, Chapter $chapter): void
    {
        $this->assertModifiable($track);
        $chemin = $this->chemin($track, $chapter);
        $this->sousVerrou($chemin, function () use ($chemin): void {
            $this->filesystem->remove($chemin);
            // Le dossier du chapitre ne sert qu'à la fiche : on ne laisse pas un dossier vide.
            if (is_dir(\dirname($chemin)) && !glob(\dirname($chemin).'/*')) {
                $this->filesystem->remove(\dirname($chemin));
            }
        });
        $this->content->reset();
    }

    private function assertModifiable(Track $track): void
    {
        if (!PackWritability::writable($track)) {
            throw new ContentException(sprintf('Le parcours « %s » est en lecture seule (%s).', $track->id, $track->directory));
        }
    }

    /** @param callable(): void $ecriture */
    private function sousVerrou(string $chemin, callable $ecriture): void
    {
        $verrou = $this->locks->createLock('lesson-md:'.$chemin);
        $verrou->acquire(true);
        try {
            $ecriture();
        } finally {
            $verrou->release();
        }
    }
}
