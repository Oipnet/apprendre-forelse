<?php

namespace App\Controller;

use App\Api\PlaygroundConfigFactory;
use App\Content\LessonRenderer;
use App\Content\Practice;
use App\Content\PracticeVisibility;
use App\Entity\User;
use App\Repository\ExerciseProgressRepository;
use App\Seo\SeoWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La Pratique : de courts exercices, à part des parcours, pour se servir d'une fonctionnalité d'un framework.
 * La liste et l'explication de chaque exercice sont publiques ; le faire demande un compte.
 */
final class PracticeController extends AbstractController
{
    public const array FRAMEWORKS = ['symfony' => 'Symfony', 'laravel' => 'Laravel', 'docker' => 'Docker', 'nuxt' => 'Nuxt'];

    public function __construct(
        private readonly PracticeVisibility $practices,
        #[Autowire(env: 'bool:REGISTRATION_INVITE_ONLY')]
        private readonly bool $inviteOnly,
    ) {
    }

    #[Route('/pratique', name: 'app_practice', methods: ['GET'])]
    public function index(
        ExerciseProgressRepository $progressRepository,
        SeoWriter $seo,
        #[MapQueryParameter] ?string $framework = null,
        #[MapQueryParameter] ?string $notion = null,
        #[MapQueryParameter] bool $nouveautes = false,
    ): Response {
        $all = $this->practices->practices();
        $seo->practiceList(array_values($all));
        $shown = array_filter($all, static fn (Practice $p) => (null === $framework || $p->framework === $framework)
            && (null === $notion || \in_array($notion, $p->exercise->concepts, true))
            && (!$nouveautes || null !== $p->version));

        $user = $this->getUser();
        $progress = $user instanceof User ? $progressRepository->findPractice($user) : [];
        $notions = array_values(array_unique(array_merge(...array_values(array_map(static fn (Practice $p) => $p->exercise->concepts, $all)))));
        sort($notions);

        return $this->render('practice/index.html.twig', [
            'items' => array_map(static fn (Practice $p) => [
                'practice' => $p,
                'state' => ($progress[$p->exercise->id] ?? null)?->getStatus()->value ?? 'todo',
            ], $shown),
            'frameworks' => array_intersect_key(self::FRAMEWORKS, array_flip(array_map(static fn (Practice $p) => $p->framework, $all))),
            'notions' => $notions,
            'filters' => ['framework' => $framework, 'notion' => $notion, 'nouveautes' => $nouveautes],
            'total' => \count($all),
        ]);
    }

    /** Avec un compte : le playground. Sans compte : la page publique de l'exercice (le problème, la fonctionnalité expliquée). */
    #[Route('/pratique/{exerciseId}', name: 'app_exercise_pratique', methods: ['GET'])]
    public function play(string $exerciseId, PlaygroundConfigFactory $configs, SeoWriter $seo, LessonRenderer $markdown): Response
    {
        $practice = $this->practices->find($exerciseId) ?? throw $this->createNotFoundException();
        $seo->practice($practice);
        $context = [
            'practice' => $practice,
            'framework' => self::FRAMEWORKS[$practice->framework] ?? ucfirst($practice->framework),
            'instructions' => $markdown->toHtmlUnderTitle($practice->exercise->instructions),
        ];

        if (!$this->getUser()) {
            return $this->render('practice/show.html.twig', [...$context, 'inviteOnly' => $this->inviteOnly]);
        }

        return $this->render('exercise/play.html.twig', [...$context, 'exercise' => $practice->exercise, 'config' => $configs->create($practice->exercise)]);
    }
}
