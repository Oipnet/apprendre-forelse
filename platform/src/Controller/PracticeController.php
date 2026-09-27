<?php

namespace App\Controller;

use App\Api\PlaygroundConfigFactory;
use App\Content\LessonRenderer;
use App\Content\Practice;
use App\Content\Framework\FrameworkRegistry;
use App\Content\PracticeCatalog;
use App\Content\PracticeFilter;
use App\Content\PracticeVersionIndex;
use App\Content\PracticeVisibility;
use App\Entity\ExerciseProgress;
use App\Entity\User;
use App\Repository\ExerciseProgressRepository;
use App\Seo\SeoWriter;
use App\Security\TrackAccessChecker;
use App\Service\TrackProgress;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La Pratique : de courts exercices, à part des parcours, pour se servir d'une fonctionnalité d'un framework.
 * Tout y est public, jusqu'à l'éditeur : c'est la porte d'entrée de la plateforme, et rien n'y est demandé
 * avant d'avoir montré quelque chose. Le compte sert à garder la progression, appeler le mentor, donner un avis.
 */
final class PracticeController extends AbstractController
{
    public function __construct(
        private readonly PracticeVisibility $practices,
        private readonly FrameworkRegistry $frameworks,
        /** Instance sur invitation : l'éditeur y reste fermé aux visiteurs, et le bas de la liste le dit. */
        #[Autowire(env: 'bool:REGISTRATION_INVITE_ONLY')]
        private readonly bool $inviteOnly,
    ) {
    }

    /** Les filtres tiennent dans l'URL (et donc fonctionnent sans JavaScript) : voir PracticeFilter. */
    #[Route('/pratique', name: 'app_practice', methods: ['GET'])]
    public function index(
        ExerciseProgressRepository $progressRepository,
        PracticeVersionIndex $versions,
        PracticeCatalog $catalog,
        SeoWriter $seo,
        #[MapQueryString(mapWhenEmpty: true)] PracticeFilter $filter,
    ): Response {
        $user = $this->getUser();
        $listing = $catalog->search($filter, $user instanceof User ? $progressRepository->findPractice($user) : []);
        $seo->practiceList($listing->all);

        return $this->render('practice/index.html.twig', [
            'groups' => $listing->groups,
            'shown' => $listing->shown,
            'frameworks' => array_intersect_key($this->frameworks->labels(), array_flip(array_map(static fn (Practice $p) => $p->framework, $listing->all))),
            'notionCounts' => $listing->notionCounts,
            'filters' => $listing->filter->toArray(),
            'versions' => $versions->all(),
            'total' => \count($listing->all),
            'newCount' => $listing->newCount,
            'completedCount' => $listing->completedCount,
            'inviteOnly' => $this->inviteOnly,
        ]);
    }

    /**
     * Une version d'un framework : ce qu'elle apporte, et les exercices qui le font pratiquer. Elle ne promet
     * pas la liste des nouveautés de la version — seulement celles qu'on peut pratiquer ici.
     *
     * Le chemin tient deux segments : « nouveautes » n'entre donc pas en conflit avec un identifiant d'exercice.
     */
    #[Route('/pratique/nouveautes/{slug}', name: 'app_practice_version', methods: ['GET'])]
    public function version(string $slug, PracticeVersionIndex $versions, ExerciseProgressRepository $progressRepository, SeoWriter $seo, LessonRenderer $markdown): Response
    {
        $version = $versions->find($slug) ?? throw $this->createNotFoundException();
        $seo->practiceVersion($version);

        $user = $this->getUser();
        $progress = $user instanceof User ? $progressRepository->findPractice($user) : [];

        return $this->render('practice/version.html.twig', [
            ...$version,
            'introHtml' => null === $version['intro'] ? null : $markdown->toHtml($version['intro']),
            'items' => array_map(
                static fn (Practice $practice) => [
                    'practice' => $practice,
                    'state' => TrackProgress::stateFrom($progress[$practice->exercise->id] ?? null),
                ],
                $version['practices'],
            ),
            'frameworks' => $this->frameworks->labels(),
        ]);
    }

    /**
     * Le playground, avec ou sans compte : un visiteur écrit le code, ses essais sont gardés dans son navigateur
     * et remontent sur son compte s'il en crée un. Seule une instance sur invitation le renvoie vers la page
     * publique de l'exercice — le problème et la fonctionnalité expliquée, sans éditeur (TrackAccessChecker).
     */
    #[Route('/pratique/{exerciseId}', name: 'app_exercise_pratique', methods: ['GET'])]
    public function play(string $exerciseId, PlaygroundConfigFactory $configs, PracticeVersionIndex $versions, SeoWriter $seo, LessonRenderer $markdown, TrackAccessChecker $access): Response
    {
        $practice = $this->practices->find($exerciseId) ?? throw $this->createNotFoundException();
        $seo->practice($practice);
        $context = [
            'practice' => $practice,
            'framework' => $this->frameworks->labelOf($practice->framework),
            'instructions' => $markdown->toHtmlUnderTitle($practice->exercise->instructions),
            // La pastille de version mène aux autres exercices de cette version, quand cette page existe.
            'versionSlug' => $versions->slugOf($practice),
        ];

        $user = $this->getUser();
        if (!$access->canAccessExercise($user instanceof User ? $user : null, $practice->exercise)) {
            return $this->render('practice/show.html.twig', $context);
        }

        return $this->render('exercise/play.html.twig', [...$context, 'exercise' => $practice->exercise, 'config' => $configs->create($practice->exercise)]);
    }
}
