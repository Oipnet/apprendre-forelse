<?php

namespace App\Content\Author;

use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Content\Track;

/** Ce que l'atelier montre des parcours : une fiche par parcours, filtrable, et le détail d'un parcours par chapitre. */
final readonly class StudioCatalog
{
    public const string PUBLIES = 'publies';
    public const string PREPARATION = 'preparation';

    public function __construct(
        private ContentRepository $content,
        private ExerciseStudio $studio,
    ) {
    }

    /**
     * La liste des parcours, filtrée par état et par texte ; les totaux portent sur tous les parcours.
     *
     * @param string|null $etat      self::PUBLIES, self::PREPARATION, ou null (tous) ; une autre valeur vaut null
     * @param string|null $recherche cherchée dans le titre et la description, sans tenir compte de la casse
     *
     * @return array<string, mixed>
     */
    public function liste(?string $etat, ?string $recherche): array
    {
        $etat = \in_array($etat, [self::PUBLIES, self::PREPARATION], true) ? $etat : null;
        $recherche = trim($recherche ?? '');
        $parcours = array_values(array_map($this->fiche(...), $this->content->tracks()));
        $retenus = array_filter($parcours, static fn (array $p) => self::aLEtat($p['track'], $etat) && self::correspond($p['track'], $recherche));

        return [
            'parcours' => array_values($retenus),
            'total' => \count($parcours),
            'enPreparation' => \count(array_filter($parcours, static fn (array $p) => $p['track']->isRestricted())),
            'chapitres' => array_sum(array_column($parcours, 'chapitres')),
            'exercices' => array_sum(array_column($parcours, 'exercices')),
            'pratique' => \count($this->content->practices()),
            'filtres' => ['etat' => $etat, 'recherche' => $recherche],
        ];
    }

    /**
     * Un parcours : ses chapitres, leurs exercices et leur XP.
     *
     * @return array<string, mixed>
     */
    public function parcours(Track $track): array
    {
        $chapitres = [];
        foreach ($track->chapters as $chapitre) {
            $exercices = $this->content->exercisesOfChapter($track, $chapitre);
            $chapitres[] = ['chapitre' => $chapitre, 'exercices' => $exercices, 'xp' => self::xp($exercices)];
        }

        return [
            'track' => $track,
            'pack' => $this->content->packs()[$track->packId],
            'modifiable' => $this->studio->modifiable($track),
            'chapitres' => $chapitres,
            'exercices' => array_merge(...array_column($chapitres, 'exercices')),
            'xp' => array_sum(array_column($chapitres, 'xp')),
            'fiches' => \count($track->lessonChapters()),
            'modifie' => $this->studio->modifieLe($track),
        ];
    }

    /** @return array<string, mixed> */
    private function fiche(Track $track): array
    {
        $exercices = $this->content->exercisesOfChapter($track);

        return [
            'track' => $track,
            'pack' => $this->content->packs()[$track->packId],
            'modifiable' => $this->studio->modifiable($track),
            'chapitres' => \count($track->chapters),
            'exercices' => \count($exercices),
            'xp' => self::xp($exercices),
            'fiches' => \count($track->lessonChapters()),
            'modifie' => $this->studio->modifieLe($track),
        ];
    }

    /** @param list<Exercise> $exercices */
    private static function xp(array $exercices): int
    {
        return array_sum(array_map(static fn (Exercise $e) => $e->xp, $exercices));
    }

    private static function aLEtat(Track $track, ?string $etat): bool
    {
        return match ($etat) {
            self::PUBLIES => !$track->isRestricted(),
            self::PREPARATION => $track->isRestricted(),
            default => true,
        };
    }

    /** Le titre et la description, comme la liste les montre : c'est ce qu'on cherche. */
    private static function correspond(Track $track, string $mots): bool
    {
        return '' === $mots || false !== mb_stripos($track->title.' '.$track->description, $mots);
    }
}
