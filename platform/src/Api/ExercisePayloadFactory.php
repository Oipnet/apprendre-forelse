<?php

namespace App\Api;

use App\Content\ChapterOutline;
use App\Content\ConceptIndex;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Content\LessonRenderer;
use App\Content\Exercise;
use App\Content\PracticeTrack;
use App\Content\PracticeVersionIndex;
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
        private PracticeVersionIndex $versions,
        private PracticeTrack $practiceTracks,
        private TrackAccessChecker $access,
        private EnvironmentArtifacts $artifacts,
        private LessonRenderer $markdown,
        private ConceptIndex $concepts,
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
        $previous = $this->content->previous($exercise);
        // Dernier exercice du parcours : on conseille le parcours suivant, s'il y en a un.
        $track = null === $exercise->trackId ? null : $this->content->findTrack($exercise->trackId);
        $nextTrack = null === $next && null !== $track ? $this->visibility->nextTrack($track) : null;
        // Un exercice de Pratique n'a ni suite ni fiche : il dit d'où vient la fonctionnalité.
        $practice = null === $exercise->trackId ? $this->content->findPractice($exercise->id) : null;
        // Après la réussite, la Pratique propose le parcours du même framework, s'il y en a un de visible.
        $practiceTrack = null === $practice ? null : $this->practiceTracks->of($practice);
        // Dernier exercice d'un chapitre doté d'une fiche de cours : la réussite la débloque.
        $inChapter = null === $track ? null : $this->content->chapterOf($exercise);
        $lesson = $inChapter?->hasLesson() && $this->content->closesChapter($exercise) ? $inChapter : null;

        $payload = [
            'id' => $exercise->id,
            'trackId' => $exercise->trackId,
            'title' => $exercise->title,
            'concepts' => $exercise->concepts,
            // Les notions qui ont leur page : les pastilles du volet Consignes y mènent (voir ConceptIndex).
            'conceptUrls' => (object) $this->conceptUrls($exercise->concepts),
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
            // La consigne telle que la page publique la montre (code coloré, sans le titre qui répète l'en-tête) :
            // le playground l'affiche au lieu de refaire le rendu avec un autre moteur.
            'instructionsHtml' => $this->markdown->toHtmlUnderTitle($exercise->instructions),
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
            // Où l'on est dans le parcours : le fil d'Ariane du playground (chapitre, « exercice n sur N »).
            'chapter' => null !== $track && null !== $inChapter ? [
                'title' => $inChapter->title,
                'number' => ChapterOutline::numberOf($track, $inChapter),
                'position' => (int) array_search($exercise->id, $inChapter->exerciseIds, true) + 1,
                'total' => \count($inChapter->exerciseIds),
                'url' => $this->urls->generate('app_chapter_summary', ['trackId' => $track->id, 'chapterId' => $inChapter->id]),
            ] : null,
            'previous' => $previous ? [
                'id' => $previous->id,
                'title' => $previous->title,
                'url' => $this->exerciseUrls->generate('app_exercise', $previous),
            ] : null,
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
                // Le résumé de la page publique : sous le titre, il dit ce que la fonctionnalité apporte.
                'summary' => $practice->summary,
                // Les autres exercices de cette version, quand ils sont assez nombreux pour avoir une page.
                'versionUrl' => null === ($slug = $this->versions->slugOf($practice)) ? null : $this->urls->generate('app_practice_version', ['slug' => $slug]),
                'pullRequest' => $practice->pullRequest,
                'published' => $practice->published->format('Y-m-d'),
                'track' => $practiceTrack ? [
                    'id' => $practiceTrack->id,
                    'title' => $practiceTrack->title,
                    'description' => $practiceTrack->description,
                    'url' => $this->urls->generate('app_track', ['trackId' => $practiceTrack->id]),
                ] : null,
            ] : null,
        ];
        if ('dev' === $this->kernelEnvironment) {
            $payload['solution'] = $this->content->solutionFiles($exercise);
        }

        return $payload;
    }

    /**
     * @param list<string> $concepts
     *
     * @return array<string, string> notion => adresse de sa page, pour celles qui en ont une
     */
    private function conceptUrls(array $concepts): array
    {
        $pages = array_column($this->concepts->all(), 'slug', 'slug');
        $urls = [];
        foreach ($concepts as $concept) {
            if (isset($pages[$slug = ConceptIndex::slug($concept)])) {
                $urls[$concept] = $this->urls->generate('app_concept', ['slug' => $slug]);
            }
        }

        return $urls;
    }
}
