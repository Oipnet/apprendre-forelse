<?php

namespace App\Content\Author;

use App\Content\ContentException;
use App\Content\Track;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

/**
 * Inscrit un nouvel exercice dans track.yaml, à la fin d'un chapitre.
 *
 * L'insertion est textuelle : le fichier garde ses commentaires et sa mise en forme,
 * ce qu'un parse/dump YAML ferait perdre.
 *
 * Chaque écriture lit, modifie puis réécrit le fichier, sous un verrou propre à ce fichier : deux auteurs qui
 * ajoutent un exercice au même parcours au même moment passent l'un après l'autre, et aucun ajout n'est perdu.
 */
final class TrackWriter
{
    public function __construct(
        private readonly LockFactory $locks,
    ) {
    }

    /** Clé du verrou d'un track.yaml : la même pour tous ceux qui l'écrivent. */
    public static function lockKey(string $fichier): string
    {
        return 'track-yaml:'.$fichier;
    }

    /** Retire un exercice de track.yaml (la ligne, et elle seule). */
    public function retirerExercice(Track $track, string $exerciceId): void
    {
        $this->sousVerrou($track, fn (string $fichier) => $this->retirer($fichier, $exerciceId));
    }

    public function ajouterExercice(Track $track, string $chapitreId, string $exerciceId): void
    {
        $this->sousVerrou($track, fn (string $fichier) => $this->ajouter($fichier, $chapitreId, $exerciceId));
    }

    /** @param callable(string): void $ecriture */
    private function sousVerrou(Track $track, callable $ecriture): void
    {
        $fichier = $track->directory.'/track.yaml';
        $verrou = $this->locks->createLock(self::lockKey($fichier));
        $verrou->acquire(true);
        try {
            $ecriture($fichier);
        } finally {
            $verrou->release();
        }
    }

    private function retirer(string $fichier, string $exerciceId): void
    {
        $lignes = explode("\n", (string) file_get_contents($fichier));
        $restantes = array_values(array_filter(
            $lignes,
            static fn (string $ligne) => !preg_match('/^\s*-\s*'.preg_quote($exerciceId, '/').'\s*$/', $ligne),
        ));

        if (\count($restantes) !== \count($lignes)) {
            (new Filesystem())->dumpFile($fichier, implode("\n", $restantes));
        }
    }

    private function ajouter(string $fichier, string $chapitreId, string $exerciceId): void
    {
        $lignes = explode("\n", (string) file_get_contents($fichier));

        $dansLeChapitre = false;
        $dansLaListe = false;
        $indentation = '      ';
        $insertion = null;
        foreach ($lignes as $numero => $ligne) {
            if (preg_match('/^\s*-?\s*id:\s*([\w-]+)\s*$/', $ligne, $trouve)) {
                if ($dansLeChapitre) {
                    break; // chapitre suivant : on insère juste avant
                }
                $dansLeChapitre = $trouve[1] === $chapitreId;
                $dansLaListe = false;
            }
            if ($dansLeChapitre && preg_match('/^\s*exercises:\s*$/', $ligne)) {
                $dansLaListe = true;
                continue;
            }
            if ($dansLaListe && preg_match('/^(\s*)-\s*([\w-]+)\s*$/', $ligne, $trouve)) {
                if ($trouve[2] === $exerciceId) {
                    return; // déjà inscrit
                }
                $indentation = $trouve[1];
                $insertion = $numero + 1;
            }
        }

        if (null === $insertion) {
            throw new ContentException(sprintf('Chapitre « %s » introuvable (ou sans exercices) dans %s.', $chapitreId, $fichier));
        }

        array_splice($lignes, $insertion, 0, [$indentation.'- '.$exerciceId]);
        (new Filesystem())->dumpFile($fichier, implode("\n", $lignes));
    }
}
