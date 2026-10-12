<?php

namespace App\Content;

use App\Content\Framework\FrameworkRegistry;
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
        private readonly EnvironmentRegistry $environments,
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

    /** Le nombre d'exercices d'une notion à partir duquel elle peut devenir le fil rouge de son framework. */
    public const int FEATURED_MINIMUM = 3;

    /**
     * L'index des notions, framework par framework, rangé par les thèmes que déclarent les packs
     * (`<pack>/notions/themes.yaml`). Un framework n'y entre que s'il a au moins une notion à ouvrir : un parcours
     * pas encore publié n'affiche pas un onglet vide.
     *
     * Dans un framework, chaque notion compte les exercices de ce framework. Une notion qu'un thème déclare sans
     * qu'elle ait (encore) de page s'annonce sans lien ; une notion qui a une page sans être rangée tombe dans
     * « Autres notions », ou dans une liste sans titre quand le framework n'a pas de thèmes. Le fil rouge, la notion
     * la plus pratiquée, a son encart : rangé dans aucun thème, il n'est pas répété dans « Autres notions ».
     *
     * @return list<array{
     *     id: string,
     *     label: string,
     *     concepts: int,
     *     featured: array{name: string, slug: string, exercises: int}|null,
     *     themes: list<array{id: string, title: string, description: string, concepts: list<array{name: string, slug: string, exercises: int, live: bool}>}>,
     * }>
     */
    public function catalog(): array
    {
        $frameworks = $this->environments->frameworks();
        $declared = $this->content->conceptThemes();
        $counts = [];
        foreach ($this->index() as $slug => $entry) {
            foreach ($entry['exercises'] as $row) {
                $framework = $this->environments->has($row['exercise']->environment) ? $this->environments->get($row['exercise']->environment)->framework->id : FrameworkRegistry::DEFAULT;
                $counts[$framework][$slug] = ($counts[$framework][$slug] ?? 0) + 1;
            }
            foreach ($entry['practices'] as $practice) {
                $counts[$practice->framework][$slug] = ($counts[$practice->framework][$slug] ?? 0) + 1;
            }
        }

        $catalog = [];
        foreach ($frameworks->all() as $id => $framework) {
            $live = $counts[$id] ?? [];
            if ([] === $live) {
                continue;
            }
            $concept = fn (string $slug) => ['name' => $this->index()[$slug]['name'], 'slug' => $slug, 'exercises' => $live[$slug], 'live' => true];

            $themes = [];
            $placed = [];
            foreach ($declared[$id] ?? [] as $theme) {
                $concepts = [];
                foreach ($theme->concepts as $name) {
                    $slug = self::slug($name);
                    $placed[$slug] = true;
                    $concepts[] = isset($live[$slug]) ? $concept($slug) : ['name' => $name, 'slug' => $slug, 'exercises' => 0, 'live' => false];
                }
                $themes[] = ['id' => $theme->id, 'title' => $theme->title, 'description' => $theme->description, 'concepts' => $concepts];
            }
            // Le fil rouge : la notion la plus pratiquée du framework, si elle l'est assez pour qu'on la remarque.
            $ranked = $live;
            arsort($ranked);
            $top = array_key_first($ranked);
            $featured = $ranked[$top] >= self::FEATURED_MINIMUM ? ['name' => $this->index()[$top]['name'], 'slug' => $top, 'exercises' => $ranked[$top]] : null;

            // Les notions qu'aucun thème ne range ; parmi les thèmes, le fil rouge a déjà son encart.
            $others = array_map($concept, array_keys(array_diff_key($live, $placed, null === $featured || [] === $themes ? [] : [$top => true])));
            $collator = new \Collator('fr_FR');
            usort($others, static fn (array $a, array $b) => $collator->compare($a['name'], $b['name']));
            if ([] !== $others) {
                $themes[] = ['id' => 'autres', 'title' => [] === $themes ? '' : 'Autres notions', 'description' => '', 'concepts' => $others];
            }

            $catalog[] = [
                'id' => $id,
                'label' => $framework->label,
                'concepts' => \count($live),
                'featured' => $featured,
                'themes' => $themes,
            ];
        }

        return $catalog;
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
     *     intro: string|null,
     *     title: string|null,
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
            // Écrite dans le pack, ou rien : sans texte, la page n'est qu'une liste de liens et reste hors des moteurs.
            'intro' => $this->content->conceptIntros()[$slug]['markdown'] ?? null,
            // Le nom de la page tel qu'on le cherche, s'il est écrit dans l'en-tête de l'intro.
            'title' => $this->content->conceptIntros()[$slug]['title'] ?? null,
        ];
    }

    /** Le fichier de l'intro écrite d'une notion, ou null : sans intro, sa page ne s'indexe pas et n'entre pas dans le sitemap. */
    public function introFile(string $slug): ?string
    {
        return $this->content->conceptIntros()[$slug]['file'] ?? null;
    }

    /**
     * Les intros écrites qui ne s'affichent nulle part : faute de frappe dans le nom du fichier, ou notion vue sur
     * un seul exercice. Signalées par content:check, jamais bloquantes.
     *
     * @return array<string, string> notion => fichier
     */
    public function orphanIntros(): array
    {
        return array_map(static fn (array $intro) => $intro['file'], array_diff_key($this->content->conceptIntros(), $this->index()));
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
