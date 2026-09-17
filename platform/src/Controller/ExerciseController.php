<?php

namespace App\Controller;

use App\Api\PlaygroundConfigFactory;
use App\Content\ContentRepository;
use App\Content\TrackVisibility;
use App\Entity\User;
use App\Payment\LockedChapterPage;
use App\Security\TrackAccessChecker;
use App\Seo\SeoWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ExerciseController extends AbstractController
{
    /**
     * Ouvert : le playground. Fermé : la page publique de l'exercice (sa consigne, et l'accès à obtenir), sans éditeur
     * ni moteur WebAssembly — 200 pour un visiteur, 403 pour un apprenant connecté sans accès.
     */
    #[Route('/parcours/{trackId}/{exerciseId}', name: 'app_exercise', methods: ['GET'])]
    public function play(string $trackId, string $exerciseId, ContentRepository $content, TrackVisibility $visibility, PlaygroundConfigFactory $configs, TrackAccessChecker $access, LockedChapterPage $locked, SeoWriter $seo): Response
    {
        $track = $visibility->find($trackId) ?? throw $this->createNotFoundException();
        $exercise = $content->findExercise($trackId, $exerciseId) ?? throw $this->createNotFoundException();
        $chapter = $content->chapterOf($exercise) ?? throw $this->createNotFoundException();
        $user = $this->getUser();
        $user = $user instanceof User ? $user : null;
        $open = $access->canAccess($user, $track, $chapter);

        if ($open || null === $user) {
            $seo->exercise($track, $chapter, $exercise);
        }
        if (!$open) {
            return $locked->exercise($track, $chapter, $exercise, $user);
        }

        return $this->render('exercise/play.html.twig', [
            ...$locked->exerciseContext($track, $chapter, $exercise),
            'config' => $configs->create($exercise),
        ]);
    }
}
