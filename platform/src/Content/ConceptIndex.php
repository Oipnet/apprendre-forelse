<?php

namespace App\Content;

use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Les notions du contenu publié, et les exercices qui les abordent.
 *
 * Les packs déclarent déjà les notions de chaque exercice (clé `concepts`) ; elles ne servaient qu'à afficher des
 * pastilles. Elles donnent ici des pages à part entière : « Boucle Twig » relie un exercice du chapitre 1 à un
 * exercice de Pratique, ce qu'aucun sommaire de parcours ne fait. C'est aussi ce qu'un visiteur cherche
 * réellement dans un moteur, là où « Les prix en pièces d'or » ne répond à aucune requête.
 *
 * N'y entre que ce qu'un visiteur sans compte peut lire : parcours publiés, Pratique publiée et parue.
 */
final class ConceptIndex implements ResetInterface
{
    /** @var array<string, array{name: string, exercises: list<array<string, mixed>>, practices: list<Practice>}>|null */
    private ?array $index = null;

    public function __construct(
        private readonly ContentRepository $content,
        private readonly PublishedContent $published,
    ) {
    }

    /**
     * Toutes les notions, par ordre alphabétique.
     *
     * @return list<array{name: string, slug: string, exercises: int}>
     */
    public function all(): array
    {
        $concepts = [];
        foreach ($this->index() as $slug => $entry) {
            $concepts[] = ['name' => $entry['name'], 'slug' => $slug, 'exercises' => \count($entry['exercises'])];
        }
        usort($concepts, static fn (array $a, array $b) => $a['name'] <=> $b['name']);

        return $concepts;
    }

    /**
     * Une notion et les exercices qui l'abordent, ou null si elle n'existe pas (ou plus).
     *
     * @return array{
     *     name: string,
     *     slug: string,
     *     exercises: list<array{exercise: Exercise, track: Track, chapter: Chapter, number: int}>,
     *     practices: list<Practice>,
     *     related: list<array{name: string, slug: string}>,
     * }|null
     */
    public function find(string $slug): ?array
    {
        $index = $this->index();
        if (!isset($index[$slug])) {
            return null;
        }

        // Les notions que ces exercices abordent aussi : le voisinage d'une notion, par fréquence décroissante.
        $neighbours = [];
        foreach ([...$index[$slug]['exercises'], ...$index[$slug]['practices']] as $row) {
            foreach (($row instanceof Practice ? $row->exercise : $row['exercise'])->concepts as $concept) {
                $neighbours[self::slug($concept)] = ($neighbours[self::slug($concept)] ?? 0) + 1;
            }
        }
        unset($neighbours[$slug]);
        // Une notion voisine sans page à elle (vue une seule fois) ne se propose pas.
        $neighbours = array_intersect_key($neighbours, $index);
        arsort($neighbours);

        return [
            ...$index[$slug],
            'slug' => $slug,
            'related' => array_map(
                static fn (string $other) => ['name' => $index[$other]['name'], 'slug' => $other],
                \array_slice(array_keys($neighbours), 0, 8),
            ),
        ];
    }

    /** L'index est refait à la demande suivante, et après une écriture dans un pack (voir l'atelier). */
    public function reset(): void
    {
        $this->index = null;
    }

    /**
     * Les dossiers de contenu derrière chaque notion, par slug : sa page change quand l'un d'eux change.
     *
     * @return array<string, list<string>>
     */
    public function directories(): array
    {
        $directories = [];
        foreach ($this->index() as $slug => $entry) {
            $directories[$slug] = array_values(array_unique([
                ...array_map(static fn (array $row) => $row['exercise']->directory, $entry['exercises']),
                ...array_map(static fn (Practice $practice) => $practice->exercise->directory, $entry['practices']),
            ]));
        }

        return $directories;
    }

    /** L'identifiant d'une notion dans une adresse : « Boucle Twig » → « boucle-twig ». */
    public static function slug(string $concept): string
    {
        return (new AsciiSlugger('fr'))->slug($concept)->lower()->toString();
    }

    /**
     * Les notions du contenu publié, par slug. Deux libellés qui donnent le même slug partagent une page, sous
     * le premier libellé rencontré : « Boucle Twig » et « boucle twig » sont une seule notion.
     *
     * @return array<string, array{
     *     name: string,
     *     exercises: list<array{exercise: Exercise, track: Track, chapter: Chapter, number: int}>,
     *     practices: list<Practice>,
     * }>
     */
    private function index(): array
    {
        if (null !== $this->index) {
            return $this->index;
        }
        $index = [];
        foreach ($this->published->tracks() as $track) {
            foreach ($this->content->exercisesOf($track) as $exercise) {
                $chapter = $this->content->chapterOf($exercise);
                if (null === $chapter) {
                    continue;
                }
                $row = ['exercise' => $exercise, 'track' => $track, 'chapter' => $chapter, 'number' => ChapterOutline::numberOf($track, $chapter)];
                foreach ($exercise->concepts as $concept) {
                    $index[self::slug($concept)] ??= ['name' => $concept, 'exercises' => [], 'practices' => []];
                    $index[self::slug($concept)]['exercises'][] = $row;
                }
            }
        }
        foreach ($this->published->practices() as $practice) {
            foreach ($practice->exercise->concepts as $concept) {
                $index[self::slug($concept)] ??= ['name' => $concept, 'exercises' => [], 'practices' => []];
                $index[self::slug($concept)]['practices'][] = $practice;
            }
        }

        // Une notion vue une seule fois ne relie rien : elle reste sur la page de son exercice, sans page à elle.
        return $this->index = array_filter($index, static fn (array $entry) => \count($entry['exercises']) + \count($entry['practices']) > 1);
    }
}
