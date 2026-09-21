<?php

namespace App\Api;

use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Content\Exercise;
use App\Content\TrackVisibility;
use App\Instance\EnvironmentArtifacts;
use App\Security\TrackAccessChecker;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Données d'un exercice pour le playground. Contrat : playground/src/app/types.ts (ExercisePayload).
 * La solution n'est JAMAIS envoyée au navigateur, sauf en environnement de développement.
 */
final readonly class ExercisePayloadFactory
{
    public function __construct(
        private ContentRepository $content,
        private TrackVisibility $visibility,
        private EnvironmentRegistry $environments,
        private Packages $packages,
        private UrlGeneratorInterface $urls,
        private ExerciseUrls $exerciseUrls,
        private TrackAccessChecker $access,
        private EnvironmentArtifacts $artifacts,
        #[Autowire('%kernel.environment%')] private string $kernelEnvironment,
        #[Autowire('%kernel.project_dir%/public')] private string $publicDir = __DIR__.'/../../public',
    ) {
    }

    /** @return array<string, mixed> */
    public function create(Exercise $exercise, Request $request): array
    {
        $environment = $this->environments->get($exercise->environment);
        $absolute = fn (string $path) => $request->getSchemeAndHttpHost().$this->packages->getUrl($path);
        // Les archives et index des environnements gardent la même URL d'une version à l'autre, et le navigateur
        // les garde un jour en cache : leur date de construction les distingue.
        $versioned = function (string $path) use ($absolute): string {
            // L'archive est soit dans public/envs, soit dans les environnements installés : sa date
            // vient de là où elle est réellement, sinon une archive installée n'aurait pas de version.
            $built = @filemtime($this->artifacts->path(basename($path)) ?? $this->publicDir.'/'.$path);

            return $absolute($path).(false === $built ? '' : '?v='.$built);
        };
        $next = $this->content->next($exercise);
        // Dernier exercice du parcours : on conseille le parcours suivant, s'il y en a un.
        $track = null === $exercise->trackId ? null : $this->content->findTrack($exercise->trackId);
        $nextTrack = null === $next && null !== $track ? $this->visibility->nextTrack($track) : null;
        // Un exercice de Pratique n'a ni suite ni fiche : il dit d'où vient la fonctionnalité.
        $practice = null === $exercise->trackId ? $this->content->findPractice($exercise->id) : null;
        // Dernier exercice d'un chapitre doté d'une fiche de cours : la réussite la débloque.
        $chapter = $this->content->closesChapter($exercise) ? $this->content->chapterOf($exercise) : null;
        $lesson = $chapter?->hasLesson() ? $chapter : null;

        $payload = [
            'id' => $exercise->id,
            'trackId' => $exercise->trackId,
            'title' => $exercise->title,
            'concepts' => $exercise->concepts,
            'xp' => $exercise->xp,
            // « free » : le premier chapitre d'un parcours, gratuit pour tout compte ; « account » : le reste, qui demande un accès.
            'access' => $this->access->isFreeExercise($exercise) ? 'free' : 'account',
            'open' => $exercise->open,
            'preview' => $exercise->preview,
            'editable' => $exercise->editable,
            'readonly' => $exercise->readonly,
            'objectives' => array_map(static fn ($o) => ['test' => $o->test, 'label' => $o->label], $exercise->objectives),
            'hints' => $exercise->hints,
            'instructions' => $exercise->instructions,
            'setup' => $exercise->setup,
            'requests' => array_map(static fn ($r) => [
                'title' => $r->title,
                'method' => $r->method,
                'path' => $r->path,
                'headers' => (object) $r->headers,
                'body' => $r->body,
            ], $exercise->requests),
            'docs' => array_map(static fn ($d) => ['title' => $d->title, 'url' => $d->url], $exercise->docs),
            // Notation des tests écrits par l'apprenant (voir Mutant).
            'ownTests' => $exercise->ownTests(),
            'mutants' => array_map(static fn ($m) => ['id' => $m->id, 'label' => $m->label, 'changes' => $m->changes], $exercise->mutants),
            'files' => $this->content->startingFiles($exercise),
            'tests' => $this->content->testFiles($exercise),
            'environment' => [
                'id' => $environment->id,
                'phpVersion' => $environment->phpVersion,
                // Ce que le navigateur sait du framework vient d'ici : console, dossiers du projet,
                // caches, namespaces… Il ne redéclare plus rien de son côté (voir FrameworkProfile).
                'framework' => $environment->framework->forBrowser(),
                'archiveUrl' => $versioned($environment->archivePath()),
                'completionIndexUrl' => $versioned($environment->completionIndexPath()),
            ],
            'next' => $next ? [
                'id' => $next->id,
                'title' => $next->title,
                'url' => $this->exerciseUrls->generate('app_exercise', $next),
            ] : null,
            'lesson' => $lesson ? [
                'title' => $lesson->title,
                'url' => $this->urls->generate('app_chapter', ['trackId' => $exercise->trackId, 'chapterId' => $lesson->id]),
            ] : null,
            'nextTrack' => $nextTrack ? [
                'id' => $nextTrack->id,
                'title' => $nextTrack->title,
                'description' => $nextTrack->description,
                'url' => $this->urls->generate('app_track', ['trackId' => $nextTrack->id]),
            ] : null,
            'practice' => $practice ? [
                'framework' => $practice->framework,
                'version' => $practice->version,
                'pullRequest' => $practice->pullRequest,
                'published' => $practice->published->format('Y-m-d'),
            ] : null,
        ];
        if ('dev' === $this->kernelEnvironment) {
            $payload['solution'] = $this->content->solutionFiles($exercise);
        }

        return $payload;
    }
}
