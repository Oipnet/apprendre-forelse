<?php

namespace App\Content;

use App\Content\Framework\FrameworkRegistry;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Les versions de framework dont la Pratique parle, et les exercices qui les font pratiquer.
 *
 * Chaque exercice de Pratique annonce déjà la version qui apporte sa fonctionnalité (clé `version`) : elle
 * n'affichait qu'une pastille et servait un filtre. Elle donne ici une page par version — « les nouveautés de
 * Symfony 8.2 » est ce qu'un développeur cherche dans un moteur, et il tombe ici sur des exercices à faire
 * plutôt que sur un billet à lire. Rien à rédiger : la page se remplit quand un exercice paraît.
 *
 * Le groupement suit le rythme de chaque framework (voir FrameworkProfile::$versionParts) : Symfony par version
 * mineure, Laravel par version majeure.
 *
 * N'y entre que ce qu'un visiteur sans compte peut lire (PublishedContent) : un exercice programmé ou en
 * préparation n'y apparaît pas, même pour un administrateur. C'est une page publique, pas une liste de travail.
 */
final class PracticeVersionIndex implements ResetInterface
{
    /**
     * Une version dont un seul exercice parle n'a pas de page : elle ne rassemblerait rien que la page de cet
     * exercice ne dise déjà, et deux pages qui se ressemblent valent moins qu'une (comme pour les notions).
     */
    public const int MINIMUM = 2;

    /** @var array<string, list<Practice>>|null */
    private ?array $index = null;

    public function __construct(
        private readonly PublishedContent $published,
        private readonly FrameworkRegistry $frameworks,
        private readonly ContentRepository $content,
    ) {
    }

    /**
     * Les versions qui ont une page, par framework puis de la plus récente à la plus ancienne.
     *
     * @return list<array{slug: string, label: string, framework: string, version: string, exercises: int}>
     */
    public function all(): array
    {
        $versions = [];
        foreach ($this->index() as $slug => $practices) {
            $versions[] = [...$this->describe($practices[0]), 'slug' => $slug, 'exercises' => \count($practices)];
        }

        return $versions;
    }

    /**
     * Une version et les exercices qui la pratiquent, ou null si elle n'a pas de page.
     *
     * @return array{
     *     slug: string,
     *     label: string,
     *     framework: string,
     *     version: string,
     *     practices: list<Practice>,
     *     others: list<array{slug: string, label: string, exercises: int}>,
     *     intro: string|null,
     *     concepts: list<string>,
     * }|null
     */
    public function find(string $slug): ?array
    {
        $index = $this->index();
        if (!isset($index[$slug])) {
            return null;
        }

        $entry = $this->describe($index[$slug][0]);

        return [
            ...$entry,
            'slug' => $slug,
            'practices' => $index[$slug],
            // Écrite dans le pack, ou rien : la page compose alors son texte avec ses notions.
            'intro' => $this->content->versionIntros()[$slug]['markdown'] ?? null,
            'concepts' => self::concepts($index[$slug]),
            // Les autres versions du même framework : de la 8.2 on passe à la 8.1, ce qu'aucune liste ne propose.
            'others' => array_values(array_map(
                static fn (array $other) => ['slug' => $other['slug'], 'label' => $other['label'], 'exercises' => $other['exercises']],
                array_filter($this->all(), static fn (array $other) => $other['framework'] === $entry['framework'] && $other['slug'] !== $slug),
            )),
        ];
    }

    /**
     * La page de nouveautés où cet exercice figure, ou null s'il n'y en a pas (pas de version déclarée, ou
     * une version qu'aucun autre exercice ne pratique).
     */
    public function slugOf(Practice $practice): ?string
    {
        if (null === $practice->version) {
            return null;
        }
        $slug = self::slug($practice->framework, $this->group($practice->framework, $practice->version));

        return isset($this->index()[$slug]) ? $slug : null;
    }

    /** Le fichier d'intro d'une version, s'il y en a un : le sitemap y lit une date de plus. */
    public function introFile(string $slug): ?string
    {
        return $this->content->versionIntros()[$slug]['file'] ?? null;
    }

    /**
     * Les intros écrites qui ne s'affichent nulle part — faute de frappe dans le nom du fichier, ou version
     * qu'un seul exercice pratique. Signalées par content:check, jamais bloquantes : l'intro peut précéder
     * le deuxième exercice.
     *
     * @return array<string, string> version => fichier
     */
    public function orphanIntros(): array
    {
        $index = $this->index();

        return array_map(
            static fn (array $intro) => $intro['file'],
            array_diff_key($this->content->versionIntros(), $index),
        );
    }

    /**
     * Les exercices de chaque version, par slug : le sitemap y lit les dates de la page.
     *
     * @return array<string, list<Practice>>
     */
    public function practices(): array
    {
        return $this->index();
    }

    /** L'index est refait à la demande suivante, et après une écriture dans un pack (voir l'atelier). */
    public function reset(): void
    {
        $this->index = null;
    }

    /** L'identifiant d'un groupe de versions dans une adresse : Symfony 8.2 → « symfony-8-2 », Laravel 13 → « laravel-13 ». */
    public static function slug(string $framework, string $version): string
    {
        return (new AsciiSlugger('fr'))->slug($framework.' '.$version)->lower()->toString();
    }

    /**
     * Les notions travaillées par les exercices d'une page, de la plus fréquente à la moins fréquente (à
     * égalité, dans l'ordre d'un index français). C'est ce dont la page parle, quand personne ne l'a écrit.
     *
     * @param list<Practice> $practices
     *
     * @return list<string>
     */
    private static function concepts(array $practices): array
    {
        $counts = [];
        foreach ($practices as $practice) {
            foreach ($practice->exercise->concepts as $concept) {
                $counts[$concept] = ($counts[$concept] ?? 0) + 1;
            }
        }
        $collator = new \Collator('fr_FR');
        uksort($counts, static fn (string $a, string $b) => $counts[$b] <=> $counts[$a] ?: $collator->compare($a, $b));

        return array_keys($counts);
    }

    /** @return array{label: string, framework: string, version: string} */
    private function describe(Practice $practice): array
    {
        $version = $this->group($practice->framework, (string) $practice->version);

        return [
            'label' => $this->label($practice->framework, $version),
            'framework' => $practice->framework,
            'version' => $version,
        ];
    }

    /**
     * La version qui fait une page, d'après le profil du framework : « 8.2.1 » reste « 8.2 » côté Symfony,
     * « 13.26 » devient « 13 » côté Laravel. Une version plus courte que demandé est gardée telle quelle.
     */
    private function group(string $framework, string $version): string
    {
        $parts = $this->frameworks->has($framework) ? $this->frameworks->get($framework)->versionParts : 2;

        return implode('.', \array_slice(explode('.', $version), 0, max(1, $parts)));
    }

    private function label(string $framework, string $version): string
    {
        return trim(($this->frameworks->has($framework) ? $this->frameworks->get($framework)->label : ucfirst($framework)).' '.$version);
    }

    /**
     * Les exercices parus qui annoncent une version, par slug de version. Un exercice sans `version` (un point
     * précis, pas une nouveauté) n'entre dans aucune page.
     *
     * @return array<string, list<Practice>>
     */
    private function index(): array
    {
        if (null !== $this->index) {
            return $this->index;
        }

        $index = [];
        foreach ($this->published->practices() as $practice) {
            if (null === $practice->version) {
                continue;
            }
            $index[self::slug($practice->framework, $this->group($practice->framework, $practice->version))][] = $practice;
        }
        $index = array_filter($index, static fn (array $practices) => \count($practices) >= self::MINIMUM);

        // L'ordre des frameworks est celui de leurs profils (Symfony d'abord), comme partout ailleurs ;
        // à framework égal, la version la plus récente ouvre la liste.
        $ranks = array_flip($this->frameworks->ids());
        uasort($index, fn (array $a, array $b) => ($ranks[$a[0]->framework] ?? \PHP_INT_MAX) <=> ($ranks[$b[0]->framework] ?? \PHP_INT_MAX)
            ?: version_compare($this->group($b[0]->framework, (string) $b[0]->version), $this->group($a[0]->framework, (string) $a[0]->version)));

        return $this->index = $index;
    }
}
