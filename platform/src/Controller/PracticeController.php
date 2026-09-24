<?php

namespace App\Controller;

use App\Api\PlaygroundConfigFactory;
use App\Content\LessonRenderer;
use App\Content\Practice;
use App\Content\Framework\FrameworkRegistry;
use App\Content\PracticeVisibility;
use App\Entity\User;
use App\Repository\ExerciseProgressRepository;
use App\Seo\SeoWriter;
use App\Security\TrackAccessChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La Pratique : de courts exercices, à part des parcours, pour se servir d'une fonctionnalité d'un framework.
 * Tout y est public, jusqu'à l'éditeur : c'est la porte d'entrée de la plateforme, et rien n'y est demandé
 * avant d'avoir montré quelque chose. Le compte sert à garder la progression, appeler le mentor, donner un avis.
 */
final class PracticeController extends AbstractController
{
    /** Au-delà, les exercices passent dans le groupe « Avant ». */
    private const int JOURS_RECENTS = 7;

    public function __construct(
        private readonly PracticeVisibility $practices,
        private readonly FrameworkRegistry $frameworks,
        /** Instance sur invitation : l'éditeur y reste fermé aux visiteurs, et le bas de la liste le dit. */
        #[Autowire(env: 'bool:REGISTRATION_INVITE_ONLY')]
        private readonly bool $inviteOnly,
    ) {
    }

    /**
     * Les filtres tiennent dans l'URL (et donc fonctionnent sans JavaScript) : un framework, des notions
     * cumulables, les nouveautés seules, une recherche, un tri.
     *
     * @param list<string> $notions
     */
    #[Route('/pratique', name: 'app_practice', methods: ['GET'])]
    public function index(
        ExerciseProgressRepository $progressRepository,
        SeoWriter $seo,
        #[MapQueryParameter] ?string $framework = null,
        #[MapQueryParameter] array $notions = [],
        #[MapQueryParameter] bool $nouveautes = false,
        #[MapQueryParameter] ?string $recherche = null,
        #[MapQueryParameter] ?string $tri = null,
    ): Response {
        $all = $this->practices->practices();
        $seo->practiceList(array_values($all));

        // Le formulaire envoie un framework vide pour « Tous » ; un tri inconnu retombe sur le plus récent.
        $framework = '' !== $framework ? $framework : null;
        $recherche = trim($recherche ?? '');
        $tri = 'titre' === $tri ? 'titre' : 'recent';

        // Les notions proposées et leur nombre se comptent sur le seul framework choisi : cocher une notion
        // ne doit pas faire fondre la liste des notions elle-même. Une notion que le framework choisi n'a pas
        // est oubliée, sinon changer de framework viderait la liste sans qu'on voie pourquoi.
        $ofFramework = array_filter($all, static fn (Practice $p) => null === $framework || $p->framework === $framework);
        $notionCounts = self::countConcepts($ofFramework);
        $notions = array_values(array_intersect(array_unique(array_filter($notions, \is_string(...))), array_keys($notionCounts)));

        $shown = array_filter(
            $ofFramework,
            static fn (Practice $p) => (!$nouveautes || null !== $p->version)
                && ([] === $notions || [] !== array_intersect($notions, $p->exercise->concepts))
                && self::matches($p, $recherche),
        );

        $user = $this->getUser();
        $progress = $user instanceof User ? $progressRepository->findPractice($user) : [];

        return $this->render('practice/index.html.twig', [
            'groups' => $this->group($this->sort($shown, $tri), $tri, $progress),
            'shown' => \count($shown),
            'frameworks' => array_intersect_key($this->frameworks->labels(), array_flip(array_map(static fn (Practice $p) => $p->framework, $all))),
            'notionCounts' => $notionCounts,
            'filters' => ['framework' => $framework, 'notions' => $notions, 'nouveautes' => $nouveautes, 'recherche' => $recherche, 'tri' => $tri],
            'total' => \count($all),
            'newCount' => \count(array_filter($all, static fn (Practice $p) => null !== $p->version)),
            'completedCount' => \count(array_filter($progress, static fn ($p) => 'completed' === $p->getStatus()->value)),
            'inviteOnly' => $this->inviteOnly,
        ]);
    }

    /**
     * Le playground, avec ou sans compte : un visiteur écrit le code, ses essais sont gardés dans son navigateur
     * et remontent sur son compte s'il en crée un. Seule une instance sur invitation le renvoie vers la page
     * publique de l'exercice — le problème et la fonctionnalité expliquée, sans éditeur (TrackAccessChecker).
     */
    #[Route('/pratique/{exerciseId}', name: 'app_exercise_pratique', methods: ['GET'])]
    public function play(string $exerciseId, PlaygroundConfigFactory $configs, SeoWriter $seo, LessonRenderer $markdown, TrackAccessChecker $access): Response
    {
        $practice = $this->practices->find($exerciseId) ?? throw $this->createNotFoundException();
        $seo->practice($practice);
        $context = [
            'practice' => $practice,
            'framework' => $this->frameworks->has($practice->framework) ? $this->frameworks->get($practice->framework)->label : ucfirst($practice->framework),
            'instructions' => $markdown->toHtmlUnderTitle($practice->exercise->instructions),
        ];

        $user = $this->getUser();
        if (!$access->canAccessExercise($user instanceof User ? $user : null, $practice->exercise)) {
            return $this->render('practice/show.html.twig', $context);
        }

        return $this->render('exercise/play.html.twig', [...$context, 'exercise' => $practice->exercise, 'config' => $configs->create($practice->exercise)]);
    }

    /** « Sécurité » se range entre « Serializer » et « Sessions », comme dans un index français. */
    private static function collator(): \Collator
    {
        return new \Collator('fr_FR');
    }

    /** Le titre, le résumé et les notions : ce que la liste montre déjà, donc ce qu'on cherche. */
    private static function matches(Practice $practice, string $words): bool
    {
        if ('' === $words) {
            return true;
        }

        $haystack = implode(' ', [$practice->exercise->title, $practice->summary, ...$practice->exercise->concepts]);

        return false !== mb_stripos($haystack, $words);
    }

    /**
     * @param array<string, Practice> $practices
     *
     * @return array<string, int> notion => nombre d'exercices, de la plus fournie à la moins fournie
     */
    private static function countConcepts(array $practices): array
    {
        $counts = [];
        foreach ($practices as $practice) {
            foreach ($practice->exercise->concepts as $concept) {
                $counts[$concept] = ($counts[$concept] ?? 0) + 1;
            }
        }
        $collator = self::collator();
        uksort($counts, static fn (string $a, string $b) => $counts[$b] <=> $counts[$a] ?: $collator->compare($a, $b));

        return $counts;
    }

    /**
     * @param array<string, Practice> $practices
     *
     * @return list<Practice>
     */
    private function sort(array $practices, string $tri): array
    {
        $practices = array_values($practices);
        if ('titre' === $tri) {
            $collator = self::collator();
            usort($practices, static fn (Practice $a, Practice $b) => $collator->compare($a->exercise->title, $b->exercise->title));

            return $practices;
        }

        usort($practices, static fn (Practice $a, Practice $b) => $b->published <=> $a->published);

        return $practices;
    }

    /**
     * Des intertitres plutôt qu'une liste sans fin : par date, la semaine écoulée se détache du reste
     * (et, pour un administrateur, ce qui est encore à venir).
     *
     * @param list<Practice>        $practices
     * @param array<string, object> $progress
     *
     * @return list<array{label: string, items: list<array{practice: Practice, state: string}>}>
     */
    private function group(array $practices, string $tri, array $progress): array
    {
        $today = new \DateTimeImmutable('today');
        $recent = $today->modify(sprintf('-%d days', self::JOURS_RECENTS - 1));
        $groups = [];
        foreach ($practices as $practice) {
            $label = match (true) {
                'titre' === $tri => 'Par titre',
                $practice->isScheduled($today) => 'À venir',
                $practice->published >= $recent => 'Cette semaine',
                default => 'Avant',
            };
            $groups[$label][] = [
                'practice' => $practice,
                'state' => ($progress[$practice->exercise->id] ?? null)?->getStatus()->value ?? 'todo',
            ];
        }

        return array_map(static fn (string $label, array $items) => ['label' => $label, 'items' => $items], array_keys($groups), $groups);
    }
}
