<?php

namespace App\Controller;

use App\Content\Chapter;
use App\Content\ContentRepository;
use App\Content\HomePage;
use App\Content\TrackVisibility;
use App\Entity\User;
use App\Export\LessonPdf;
use App\Export\PdfResponse;
use App\Repository\ExerciseProgressRepository;
use App\Security\TrackAccessChecker;
use App\Seo\Page\CourseSeo;
use App\Service\ChapterSummary;
use App\Service\TrackOverview;
use App\Service\TrackProgress;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final class TrackController extends AbstractController
{
    use TargetPathTrait;

    /** Le catalogue : tous les parcours publiés, à filtrer par framework (?framework=, non indexé : voir SearchIndexing). */
    #[Route('/parcours', name: 'app_tracks', methods: ['GET'])]
    public function index(
        HomePage $page,
        TrackVisibility $visibility,
        CourseSeo $seo,
        #[Autowire(env: 'bool:REGISTRATION_INVITE_ONLY')] bool $inviteOnly,
        #[MapQueryParameter] ?string $framework = null,
    ): Response {
        $seo->catalogue(array_values($visibility->tracks()));
        $user = $this->getUser();

        return $this->render('track/index.html.twig', [...$page->catalogue($user instanceof User ? $user : null, $framework), 'inviteOnly' => $inviteOnly]);
    }

    #[Route('/parcours/{trackId}', name: 'app_track', methods: ['GET'])]
    public function show(string $trackId, TrackVisibility $visibility, TrackOverview $overview, CourseSeo $seo): Response
    {
        $track = $visibility->find($trackId) ?? throw $this->createNotFoundException();
        $seo->track($track);
        $user = $this->getUser();

        return $this->render('track/show.html.twig', $overview->of($track, $user instanceof User ? $user : null, $this->isGranted(User::ROLE_AUTEUR)));
    }

    /**
     * Toutes les fiches de cours du parcours en un seul PDF, une fois tous ses exercices réussis.
     * Le chemin ressemble à celui d'un exercice (/parcours/{trackId}/{exerciseId}) : priorité au livret.
     */
    #[Route('/parcours/{trackId}/livret.pdf', name: 'app_track_booklet', methods: ['GET'], priority: 1)]
    public function booklet(string $trackId, Request $request, ContentRepository $content, TrackVisibility $visibility, ExerciseProgressRepository $progressRepository, ChapterSummary $summary, LessonPdf $pdf, TrackAccessChecker $access): Response
    {
        $track = $visibility->find($trackId) ?? throw $this->createNotFoundException();
        $chapters = $track->lessonChapters();
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
}
