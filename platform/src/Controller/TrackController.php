<?php

namespace App\Controller;

use App\Content\Chapter;
use App\Content\ContentRepository;
use App\Content\TrackVisibility;
use App\Content\Track;
use App\Entity\User;
use App\Export\LessonPdf;
use App\Payment\TrackOfferFactory;
use App\Repository\ExerciseProgressRepository;
use App\Security\TrackAccessChecker;
use App\Seo\SeoWriter;
use App\Service\ChapterSummary;
use App\Service\LessonAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final class TrackController extends AbstractController
{
    use TargetPathTrait;

    #[Route('/parcours/{trackId}', name: 'app_track', methods: ['GET'])]
    public function show(string $trackId, ContentRepository $content, TrackVisibility $visibility, ExerciseProgressRepository $progressRepository, LessonAccess $lessonAccess, TrackAccessChecker $access, TrackOfferFactory $offers, SeoWriter $seo): Response
    {
        $track = $visibility->find($trackId) ?? throw $this->createNotFoundException();
        $seo->track($track);
        $user = $this->getUser();
        $user = $user instanceof User ? $user : null;
        $progress = null !== $user ? $progressRepository->findByTrack($user, $track->id) : [];

        $chapters = [];
        $completed = 0;
        $xpTotal = $xpEarned = 0;
        // « Vous en êtes là » : le premier exercice pas encore réussi, dans l'ordre du parcours.
        $current = null;
        foreach ($track->chapters as $number => $chapter) {
            $items = [];
            $chapterXp = 0;
            foreach ($chapter->exerciseIds as $position => $exerciseId) {
                $exercise = $content->findExercise($track->id, $exerciseId);
                $state = isset($progress[$exerciseId]) ? $progress[$exerciseId]->getStatus()->value : 'todo';
                $completed += 'completed' === $state ? 1 : 0;
                $chapterXp += $exercise->xp ?? 0;
                $xpEarned += ($progress[$exerciseId] ?? null)?->getXpEarned() ?? 0;
                $items[] = [
                    'exercise' => $exercise,
                    'state' => $state,
                    'xpEarned' => ($progress[$exerciseId] ?? null)?->getXpEarned(),
                    // Le dernier exercice d'un chapitre titré « Boss » : mis en valeur.
                    'boss' => null !== $exercise && $exercise->isBoss(),
                ];
                if (null === $current && null !== $exercise && 'completed' !== $state) {
                    $current = ['exercise' => $exercise, 'chapter' => $number + 1, 'position' => $position + 1, 'started' => 'todo' !== $state];
                }
            }
            $xpTotal += $chapterXp;
            $chapters[] = [
                'chapter' => $chapter,
                'number' => $number + 1,
                'items' => $items,
                'xp' => $chapterXp,
                // La fiche de cours, si le chapitre en a une : lisible ou encore verrouillée.
                'lesson' => $chapter->hasLesson() ? $lessonAccess->status($user, $track, $chapter, $progress) : null,
                // Premier chapitre libre ; les autres demandent l'accès au parcours (la progression s'affiche quand même).
                'free' => TrackAccessChecker::isFreeChapter($track, $chapter),
                'locked' => !$access->canAccess($user, $track, $chapter),
            ];
        }

        return $this->render('track/show.html.twig', [
            'track' => $track,
            'pack' => $content->packs()[$track->packId],
            'chapters' => $chapters,
            'completed' => $completed,
            'total' => \count($track->exerciseIds()),
            'xpTotal' => $xpTotal,
            'xpEarned' => $xpEarned,
            'current' => $current,
            'started' => [] !== $progress,
            'nextTrack' => $visibility->nextTrack($track),
            'offer' => $offers->create($track, $user),
            // Le livret regroupe les fiches : proposé quand tout le parcours est réussi (ou à un auteur).
            'booklet' => self::lessonChapters($track) && ($this->isGranted(User::ROLE_AUTEUR) || ($total = \count($track->exerciseIds())) && $completed === $total),
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
        $remaining = \count(array_filter($track->exerciseIds(), static fn (string $id) => !isset($progress[$id]) || !$progress[$id]->isCompleted()));
        if ($remaining && !$this->isGranted(User::ROLE_AUTEUR)) {
            $this->addFlash('info', sprintf('Le livret se débloque une fois le parcours terminé : encore %d exercice%s à réussir.', $remaining, $remaining > 1 ? 's' : ''));

            return $this->redirectToRoute('app_track', ['trackId' => $track->id]);
        }

        $summaries = array_map(static fn (Chapter $c) => $summary->summarize($track, $c, $progress), $chapters);

        return ChapterController::pdfResponse(
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
