<?php

namespace App\Controller;

use App\Content\Chapter;
use App\Content\ContentRepository;
use App\Content\LessonRenderer;
use App\Content\Track;
use App\Content\TrackVisibility;
use App\Entity\User;
use App\Export\LessonPdf;
use App\Payment\LockedChapterPage;
use App\Repository\ExerciseProgressRepository;
use App\Security\TrackAccessChecker;
use App\Service\ChapterSummary;
use App\Service\LessonAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/** La fiche de cours de fin de chapitre, en HTML et en PDF. */
final class ChapterController extends AbstractController
{
    use TargetPathTrait;

    public function __construct(
        private readonly ContentRepository $content,
        private readonly TrackVisibility $visibility,
        private readonly LessonAccess $access,
        private readonly ExerciseProgressRepository $progressRepository,
        private readonly ChapterSummary $summary,
        private readonly TrackAccessChecker $trackAccess,
        private readonly LockedChapterPage $lockedPage,
    ) {
    }

    #[Route('/parcours/{trackId}/chapitre/{chapterId}', name: 'app_chapter', methods: ['GET'])]
    public function show(string $trackId, string $chapterId, Request $request, LessonRenderer $renderer): Response
    {
        $lesson = $this->lesson($trackId, $chapterId, $request);
        if ($lesson instanceof Response) {
            return $lesson;
        }
        ['track' => $track, 'chapter' => $chapter, 'number' => $number] = $lesson;
        $nextChapter = $track->chapters[$number] ?? null;

        return $this->render('chapter/show.html.twig', [
            ...$lesson,
            'pack' => $this->content->packs()[$track->packId],
            'html' => $renderer->toHtml((string) $chapter->lesson),
            'nextChapter' => $nextChapter,
            'nextExercise' => $nextChapter && $nextChapter->exerciseIds ? $this->content->findExercise($track->id, $nextChapter->exerciseIds[0]) : null,
        ]);
    }

    #[Route('/parcours/{trackId}/chapitre/{chapterId}/fiche.pdf', name: 'app_chapter_pdf', methods: ['GET'])]
    public function pdf(string $trackId, string $chapterId, Request $request, LessonPdf $pdf): Response
    {
        $lesson = $this->lesson($trackId, $chapterId, $request);
        if ($lesson instanceof Response) {
            return $lesson;
        }
        ['track' => $track, 'chapter' => $chapter, 'number' => $number] = $lesson;
        /** @var User $user */
        $user = $this->getUser();

        return self::pdfResponse(
            $pdf->render($this->content->packs()[$track->packId], $track, $lesson, $user),
            LessonPdf::filename($track, $number, $chapter),
        );
    }

    /** Un PDF personnalisé : généré à chaque demande, jamais mis en cache. */
    public static function pdfResponse(string $pdf, string $filename): Response
    {
        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $filename));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * Ce qu'une fiche de cours accessible donne à voir, ou la réponse qui en tient lieu
     * (404 sans fiche, connexion pour un invité, retour au parcours tant que le chapitre n'est pas réussi).
     *
     * @return Response|array{track: Track, chapter: Chapter, number: int, concepts: list<string>, xp: int}
     */
    private function lesson(string $trackId, string $chapterId, Request $request): Response|array
    {
        $track = $this->visibility->find($trackId) ?? throw $this->createNotFoundException();
        $chapter = $this->content->findChapter($track, $chapterId) ?? throw $this->createNotFoundException();
        if (!$chapter->hasLesson()) {
            throw $this->createNotFoundException(sprintf('Le chapitre « %s » n\'a pas de fiche de cours.', $chapter->id));
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            // Après connexion, l'apprenant revient sur la fiche.
            $this->saveTargetPath($request->getSession(), 'main', $request->getRequestUri());
            $this->addFlash('info', 'Connectez-vous pour lire la fiche de cours du chapitre.');

            return $this->redirectToRoute('app_login');
        }

        // La fiche est du contenu du parcours : un chapitre réussi mais dont l'accès a expiré reste fermé.
        if (!$this->trackAccess->canAccess($user, $track, $chapter)) {
            return $this->lockedPage->render($track, $chapter, $user);
        }

        $progress = $this->progressRepository->findByTrack($user, $track->id);
        $status = $this->access->status($user, $track, $chapter, $progress);
        if (!$status['unlocked']) {
            $this->addFlash('info', sprintf(
                'La fiche du chapitre « %s » se débloque une fois ses exercices réussis : encore %d à terminer.',
                $chapter->title,
                $status['remaining'],
            ));

            return $this->redirectToRoute('app_track', ['trackId' => $track->id]);
        }

        return ['track' => $track, ...$this->summary->summarize($track, $chapter, $progress)];
    }
}
