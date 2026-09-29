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

        $trouve = false;
        $dansLeChapitre = false;
        $dansLaListe = false;
        $indentation = null;
        $insertion = null;
        $cleIndentation = '    ';
        $listeVide = null;      // « exercises: [] »
        $cleExercices = null;   // « exercises: », suivie ou non d'entrées
        $derniereLigne = null;  // dernière ligne utile du chapitre
        foreach ($lignes as $numero => $ligne) {
            // « id » en début de ligne est celui du parcours, pas d'un chapitre.
            if (preg_match('/^(\s*)(-?\s*)id:\s*([\w-]+)\s*$/', $ligne, $id) && '' !== $id[1].$id[2]) {
                if ($dansLeChapitre) {
                    break; // chapitre suivant : on insère juste avant
                }
                $dansLeChapitre = $id[3] === $chapitreId;
                $dansLaListe = false;
                if ($dansLeChapitre) {
                    $trouve = true;
                    // Les clés du chapitre s'alignent sur « id », après le tiret.
                    $cleIndentation = $id[1].str_repeat(' ', \strlen($id[2]));
                }
            }
            if (!$dansLeChapitre) {
                continue;
            }
            if ('' !== trim($ligne) && !str_starts_with(ltrim($ligne), '#')) {
                $derniereLigne = $numero;
            }
            if (preg_match('/^(\s*)exercises:\s*\[\s*\]\s*(#.*)?$/', $ligne, $vide)) {
                $listeVide = [$numero, $vide[1]];
                continue;
            }
            if (preg_match('/^(\s*)exercises:\s*(#.*)?$/', $ligne, $cle)) {
                $dansLaListe = true;
                $cleExercices = [$numero, $cle[1]];
                continue;
            }
            if ($dansLaListe && preg_match('/^(\s*)-\s*([\w-]+)\s*$/', $ligne, $entree)) {
                if ($entree[2] === $exerciceId) {
                    return; // déjà inscrit
                }
                $indentation = $entree[1];
                $insertion = $numero + 1;
            }
        }

        if (!$trouve) {
            throw new ContentException(sprintf('Chapitre « %s » introuvable dans %s.', $chapitreId, $fichier));
        }
        if (null !== $insertion) {
            array_splice($lignes, $insertion, 0, [$indentation.'- '.$exerciceId]);
        } elseif (null !== $listeVide) {
            // Premier exercice d'un chapitre « exercises: [] » : la liste s'ouvre.
            array_splice($lignes, $listeVide[0], 1, [$listeVide[1].'exercises:', $listeVide[1].'  - '.$exerciceId]);
        } elseif (null !== $cleExercices) {
            array_splice($lignes, $cleExercices[0] + 1, 0, [$cleExercices[1].'  - '.$exerciceId]);
        } else {
            // Chapitre sans clé « exercises » : elle s'ajoute après sa dernière ligne.
            array_splice($lignes, (int) $derniereLigne + 1, 0, [$cleIndentation.'exercises:', $cleIndentation.'  - '.$exerciceId]);
        }
        (new Filesystem())->dumpFile($fichier, implode("\n", $lignes));
    }
}
