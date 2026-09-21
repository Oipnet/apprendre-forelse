<?php

namespace App\Payment;

use App\Content\Chapter;
use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Content\LessonRenderer;
use App\Content\Track;
use App\Entity\User;
use App\Security\TrackAccessChecker;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * La page d'un chapitre fermé : le prix, le bouton d'achat, la progression gardée. Pour un exercice, sa consigne reste
 * lisible : 200 pour un visiteur (la page publique de l'exercice), 403 pour un apprenant connecté sans accès.
 */
final readonly class LockedChapterPage
{
    public function __construct(
        private Environment $twig,
        private TrackOfferFactory $offers,
        private ContentRepository $content,
        private LessonRenderer $markdown,
        #[Autowire(env: 'bool:REGISTRATION_INVITE_ONLY')]
        private bool $inviteOnly,
    ) {
    }

    /**
     * Ce que la page d'un exercice de parcours montre à tous, qu'il soit ouvert ou non (voir exercise/_intro.html.twig).
     *
     * @return array<string, mixed>
     */
    public function exerciseContext(Track $track, Chapter $chapter, Exercise $exercise): array
    {
        return [
            'track' => $track,
            'chapter' => $chapter,
            'chapterNumber' => (int) array_search($chapter->id, array_map(static fn (Chapter $c) => $c->id, $track->chapters), true) + 1,
            'exercise' => $exercise,
            'position' => (int) array_search($exercise->id, $chapter->exerciseIds, true) + 1,
            'instructions' => $this->markdown->toHtmlUnderTitle($exercise->instructions),
        ];
    }

    public function exercise(Track $track, Chapter $chapter, Exercise $exercise, ?User $user): Response
    {
        $firstId = $track->exerciseIds()[0] ?? null;

        return new Response($this->twig->render('exercise/show.html.twig', [
            ...$this->exerciseContext($track, $chapter, $exercise),
            'offer' => $this->offers->create($track, $user),
            'inviteOnly' => $this->inviteOnly,
            // Le premier chapitre est gratuit : un visiteur n'a qu'un compte à créer, pas un parcours à acheter.
            'freeChapter' => TrackAccessChecker::isFreeChapter($track, $chapter),
            'firstExercise' => null === $firstId ? null : $this->content->findExercise($track->id, $firstId),
        ]), null === $user ? Response::HTTP_OK : Response::HTTP_FORBIDDEN);
    }

    public function render(Track $track, Chapter $chapter, User $user): Response
    {
        return new Response($this->twig->render('track/locked.html.twig', [
            'track' => $track,
            'chapter' => $chapter,
            'offer' => $this->offers->create($track, $user),
        ]), Response::HTTP_FORBIDDEN);
    }
}
