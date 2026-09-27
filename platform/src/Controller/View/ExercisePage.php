<?php

namespace App\Controller\View;

use App\Content\Chapter;
use App\Content\ChapterOutline;
use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Content\LessonRenderer;
use App\Content\Track;
use App\Entity\User;
use App\Payment\TrackOfferFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Les pages d'un exercice de parcours et d'un chapitre fermé : le prix, le bouton d'achat, la progression gardée. Un
 * exercice fermé garde sa consigne lisible : 200 pour un visiteur (sa page publique), 403 pour un apprenant sans accès.
 */
final readonly class ExercisePage
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
    public function context(Track $track, Chapter $chapter, Exercise $exercise): array
    {
        return [
            'track' => $track,
            'chapter' => $chapter,
            'chapterNumber' => ChapterOutline::numberOf($track, $chapter),
            'exercise' => $exercise,
            'position' => (int) array_search($exercise->id, $chapter->exerciseIds, true) + 1,
            'instructions' => $this->markdown->toHtmlUnderTitle($exercise->instructions),
            'siblings' => $this->siblings($track, $chapter, $exercise),
        ];
    }

    /**
     * Les autres exercices du chapitre. Une page d'exercice n'avait qu'un lien interne sortant, celui du parcours :
     * les 74 exercices pendaient tous d'une seule page, sans se relier entre eux.
     *
     * @return list<array{exercise: Exercise, position: int}>
     */
    private function siblings(Track $track, Chapter $chapter, Exercise $exercise): array
    {
        $siblings = [];
        foreach ($chapter->exerciseIds as $position => $exerciseId) {
            $sibling = $exerciseId === $exercise->id ? null : $this->content->findExercise($track->id, $exerciseId);
            if (null !== $sibling) {
                $siblings[] = ['exercise' => $sibling, 'position' => $position + 1];
            }
        }

        return $siblings;
    }

    public function locked(Track $track, Chapter $chapter, Exercise $exercise, ?User $user): Response
    {
        $firstId = $track->exerciseIds()[0] ?? null;

        return new Response($this->twig->render('exercise/show.html.twig', [
            ...$this->context($track, $chapter, $exercise),
            'offer' => $this->offers->create($track, $user),
            'inviteOnly' => $this->inviteOnly,
            // Le premier chapitre est gratuit : un visiteur n'a qu'un compte à créer, pas un parcours à acheter.
            'freeChapter' => $track->isFreeChapter($chapter),
            'firstExercise' => null === $firstId ? null : $this->content->findExercise($track->id, $firstId),
        ]), null === $user ? Response::HTTP_OK : Response::HTTP_FORBIDDEN);
    }

    public function lockedChapter(Track $track, Chapter $chapter, User $user): Response
    {
        return new Response($this->twig->render('track/locked.html.twig', [
            'track' => $track,
            'chapter' => $chapter,
            'offer' => $this->offers->create($track, $user),
        ]), Response::HTTP_FORBIDDEN);
    }
}
