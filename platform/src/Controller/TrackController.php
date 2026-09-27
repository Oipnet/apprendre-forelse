<?php

namespace App\Controller;

use App\Content\Chapter;
use App\Content\ContentRepository;
use App\Content\Track;
use App\Content\TrackVisibility;
use App\Entity\User;
use App\Export\LessonPdf;
use App\Export\PdfResponse;
use App\Payment\TrackOfferFactory;
use App\Repository\ExerciseProgressRepository;
use App\Security\TrackAccessChecker;
use App\Seo\Page\CourseSeo;
use App\Service\ChapterSummary;
use App\Service\TrackProgress;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final class TrackController extends AbstractController
{
    use TargetPathTrait;

    #[Route('/parcours/{trackId}', name: 'app_track', methods: ['GET'])]
    public function show(string $trackId, ContentRepository $content, TrackVisibility $visibility, ExerciseProgressRepository $progressRepository, TrackAccessChecker $access, TrackOfferFactory $offers, CourseSeo $seo): Response
    {
        $track = $visibility->find($trackId) ?? throw $this->createNotFoundException();
        $seo->track($track);
        $user = $this->getUser();
        $user = $user instanceof User ? $user : null;
        $progress = TrackProgress::of($track, null !== $user ? $progressRepository->findByTrack($user, $track->id) : []);
        $reviewer = $this->isGranted(User::ROLE_AUTEUR);

        $chapters = [];
        $xpTotal = 0;
        // « Vous en êtes là » : le premier exercice pas encore réussi, dans l'ordre du parcours.
        $nextId = $progress->next();
        $current = null;
        foreach ($track->chapters as $number => $chapter) {
            $items = [];
            $chapterXp = 0;
            foreach ($chapter->exerciseIds as $position => $exerciseId) {
                $exercise = $content->findExercise($track->id, $exerciseId);
                $state = $progress->stateOf($exerciseId);
                $chapterXp += $exercise->xp ?? 0;
                $items[] = [
                    'exercise' => $exercise,
                    'state' => $state,
                    'xpEarned' => $progress->xpEarnedOn($exerciseId),
                    // Le dernier exercice d'un chapitre titré « Boss » : mis en valeur.
                    'boss' => null !== $exercise && $exercise->isBoss(),
                ];
                if ($exerciseId === $nextId && null !== $exercise) {
                    $current = ['exercise' => $exercise, 'chapter' => $number + 1, 'position' => $position + 1, 'started' => TrackProgress::TODO !== $state];
                }
            }
            $xpTotal += $chapterXp;
            $chapters[] = [
                'chapter' => $chapter,
                'number' => $number + 1,
                'items' => $items,
                'xp' => $chapterXp,
                // La fiche de cours, si le chapitre en a une : lisible ou encore verrouillée.
                'lesson' => $chapter->hasLesson() ? $progress->lesson($chapter, $reviewer) : null,
                // Premier chapitre libre ; les autres demandent l'accès au parcours (la progression s'affiche quand même).
                'free' => $track->isFreeChapter($chapter),
                'locked' => !$access->canAccess($user, $track, $chapter),
            ];
        }

        return $this->render('track/show.html.twig', [
            'track' => $track,
            'pack' => $content->packs()[$track->packId],
            'chapters' => $chapters,
            'completed' => $progress->completedCount(),
            'total' => $progress->total(),
            'xpTotal' => $xpTotal,
            'xpEarned' => $progress->xpEarned(),
            'current' => $current,
            'started' => $progress->hasStarted(),
            'nextTrack' => $visibility->nextTrack($track),
            'offer' => $offers->create($track, $user),
            // Le livret regroupe les fiches : proposé quand tout le parcours est réussi (ou à un auteur).
            'booklet' => self::lessonChapters($track) && ($reviewer || $progress->isComplete()),
        ]);
    }

    /**
     * Toutes les fiches de cours du parcours en un seul PDF, une fois tous ses exercices réussis.
     * Le chemin ressemble à celui d'un exercice (/parcours/{trackId}/{exerciseId}) : priorité au livret.
     */
    #[Route('/parcours/{trackId}/livret.pdf', name: 'app_track_booklet', methods: ['GET'], priority: 1)]
    public function booklet(string $trackId, Request $request, ContentRepository $content, TrackVisibility $visibility, ExerciseProgressRepository $progressRepository, ChapterSummary $summary, LessonPdf $pdf, TrackAccessChecker $access): Response
    {
        $track = $visibility->find($trackId) ?? throw $this->createNotFoundException();
        $chapters = self::lessonChapters($track);
        if (!$chapters) {
            throw $this->createNotFoundException(sprintf('Le parcours « %s » n\'a aucune fiche de cours.', $track->id));
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            $this->saveTargetPath($request->getSession(), 'main', $request->getRequestUri());
            $this->addFlash('info', 'Connectez-vous pour télécharger le livret du parcours.');

            return $this->redirectToRoute('app_login');
        }

        if (!$access->hasFullAccess($user, $track)) {
            $this->addFlash('info', 'Le livret fait partie du parcours complet : il faut un accès au parcours pour le télécharger.');

            return $this->redirectToRoute('app_track', ['trackId' => $track->id]);
        }

        $progress = $progressRepository->findByTrack($user, $track->id);
        $remaining = TrackProgress::of($track, $progress)->remaining();
        if ($remaining && !$this->isGranted(User::ROLE_AUTEUR)) {
            $this->addFlash('info', sprintf('Le livret se débloque une fois le parcours terminé : encore %d exercice%s à réussir.', $remaining, $remaining > 1 ? 's' : ''));

            return $this->redirectToRoute('app_track', ['trackId' => $track->id]);
        }

        $summaries = array_map(static fn (Chapter $c) => $summary->summarize($track, $c, $progress), $chapters);

        return new PdfResponse(
            $pdf->renderBooklet($content->packs()[$track->packId], $track, $summaries, $user),
            LessonPdf::bookletFilename($track),
        );
    }

    /** @return list<Chapter> les chapitres dotés d'une fiche de cours */
    private static function lessonChapters(Track $track): array
    {
        return array_values(array_filter($track->chapters, static fn (Chapter $c) => $c->hasLesson()));
    }
}
