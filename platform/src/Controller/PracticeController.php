<?php

namespace App\Controller;

use App\Api\PlaygroundConfigFactory;
use App\Content\Exercise;
use App\Content\Framework\FrameworkRegistry;
use App\Content\LessonRenderer;
use App\Content\Practice;
use App\Content\PracticeCatalog;
use App\Content\PracticeFilter;
use App\Content\PracticeTrack;
use App\Content\PracticeVersionIndex;
use App\Content\PracticeVisibility;
use App\Entity\ExerciseProgress;
use App\Entity\User;
use App\Repository\ExerciseProgressRepository;
use App\Security\TrackAccessChecker;
use App\Seo\Page\PracticeSeo;
use App\Service\TrackProgress;
use App\Share\PracticeShare;
use App\Share\ShareImage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
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
    #[Route(['fr' => '/pratique', 'en' => '/en/practice'], name: 'app_practice', methods: ['GET'])]
    public function index(
        ExerciseProgressRepository $progressRepository,
        PracticeVersionIndex $versions,
        PracticeCatalog $catalog,
        PracticeSeo $seo,
        #[MapQueryString(mapWhenEmpty: true)] PracticeFilter $filter,
    ): Response {
        $user = $this->getUser();
        $listing = $catalog->search($filter, $user instanceof User ? $progressRepository->findPractice($user) : []);
        $seo->index($listing->all);

        return $this->render('practice/index.html.twig', [
            'groups' => $listing->groups,
            'shown' => $listing->shown,
            'frameworks' => array_intersect_key($this->frameworks->labels(), array_flip(array_map(static fn (Practice $p) => $p->framework, $listing->all))),
            'notionCounts' => $listing->notionCounts,
            'filters' => $listing->filter->toArray(),
            // Les pages de nouveautés sont en français : la liste anglaise n'y renvoie pas.
            'versions' => $this->translated() ? [] : $versions->all(),
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
    public function version(string $slug, PracticeVersionIndex $versions, ExerciseProgressRepository $progressRepository, PracticeSeo $seo, LessonRenderer $markdown): Response
    {
        $version = $versions->find($slug) ?? throw $this->createNotFoundException();
        $seo->version($version);

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
     * L'image de partage d'un exercice, dessinée par le moteur au premier appel (voir ShareImage). L'adresse que
     * donnent les pages porte sa version : sous cette adresse, l'image ne change plus.
     */
    #[Route(['fr' => '/pratique/{exerciseId}/image-de-partage', 'en' => '/en/practice/{exerciseId}/share-image'], name: 'app_practice_image', methods: ['GET'], priority: 1)]
    public function image(string $exerciseId, Request $request, PracticeShare $share, ShareImage $images): BinaryFileResponse
    {
        $practice = $this->practices->find($exerciseId) ?? throw $this->createNotFoundException();

        return $images->response($share->card($practice), $request);
    }

    /**
     * Le playground, avec ou sans compte : un visiteur écrit le code, ses essais sont gardés dans son navigateur
     * et remontent sur son compte s'il en crée un. Seule une instance sur invitation le renvoie vers la page
     * publique de l'exercice — le problème et la fonctionnalité expliquée, sans éditeur (TrackAccessChecker).
     */
    #[Route(['fr' => '/pratique/{exerciseId}', 'en' => '/en/practice/{exerciseId}'], name: 'app_exercise_pratique', methods: ['GET'])]
    public function play(string $exerciseId, PlaygroundConfigFactory $configs, PracticeVersionIndex $versions, PracticeSeo $seo, LessonRenderer $markdown, TrackAccessChecker $access, PracticeTrack $tracks, PracticeShare $share): Response
    {
        $practice = $this->practices->find($exerciseId) ?? throw $this->createNotFoundException();
        $seo->practice($practice);
        $context = [
            'practice' => $practice,
            'framework' => $this->frameworks->labelOf($practice->framework),
            'instructions' => $markdown->toHtmlUnderTitle($practice->exercise->instructions),
            // La pastille de version mène aux autres exercices de cette version, quand cette page existe.
            // Les pages de nouveautés et les parcours sont en français : une traduction n'y renvoie pas.
            'versionSlug' => $this->translated() ? null : $versions->slugOf($practice),
            // Pour aller plus loin : le parcours du même framework, s'il y en a un de visible.
            'track' => $this->translated() ? null : $tracks->of($practice),
            // Les liens de partage : inviter à faire l'exercice, ou annoncer l'avoir réussi (panneau de réussite).
            'share' => ['invite' => $share->links($practice), 'success' => $share->links($practice, true)],
        ];

        $user = $this->getUser();
        if (!$access->canAccessExercise($user instanceof User ? $user : null, $practice->exercise)) {
            return $this->render('practice/show.html.twig', $context);
        }

        return $this->render('exercise/play.html.twig', [...$context, 'exercise' => $practice->exercise, 'config' => [...$configs->create($practice->exercise), 'share' => $context['share']]]);
    }

    /** La page est-elle dans une autre langue que celle des packs (/en/practice) ? */
    private function translated(): bool
    {
        return Exercise::LOCALE !== $this->practices->locale();
    }
}
