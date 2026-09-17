<?php

namespace App\Content\Author;

use App\Content\ContentException;
use App\Content\Track;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Inscrit un nouvel exercice dans track.yaml, à la fin d'un chapitre.
 *
 * L'insertion est textuelle : le fichier garde ses commentaires et sa mise en forme,
 * ce qu'un parse/dump YAML ferait perdre.
 */
final class TrackWriter
{
    /** Retire un exercice de track.yaml (la ligne, et elle seule). */
    public function retirerExercice(Track $track, string $exerciceId): void
    {
        $fichier = $track->directory.'/track.yaml';
        $lignes = explode("\n", (string) file_get_contents($fichier));
        $restantes = array_values(array_filter(
            $lignes,
            static fn (string $ligne) => !preg_match('/^\s*-\s*'.preg_quote($exerciceId, '/').'\s*$/', $ligne),
        ));

        if (\count($restantes) !== \count($lignes)) {
            (new Filesystem())->dumpFile($fichier, implode("\n", $restantes));
        }
    }

    public function ajouterExercice(Track $track, string $chapitreId, string $exerciceId): void
    {
        $fichier = $track->directory.'/track.yaml';
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
