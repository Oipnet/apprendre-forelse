<?php

namespace App\Api;

use App\Content\ConceptIndex;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Content\LessonRenderer;
use App\Content\Exercise;
use App\Content\PracticeVersionIndex;
use App\Security\TrackAccessChecker;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Données d'un exercice pour le playground. Contrat : playground/src/app/types.ts (ExercisePayload).
 * La solution n'est JAMAIS envoyée au navigateur, sauf en environnement de développement.
 */
final readonly class ExercisePayloadFactory
{
    public function __construct(
        private ContentRepository $content,
        private EnvironmentRegistry $environments,
        private ExerciseNavigation $navigation,
        private EnvironmentAssetUrls $assets,
        private PracticeVersionIndex $versions,
        private TrackAccessChecker $access,
        private LessonRenderer $markdown,
        private ConceptIndex $concepts,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        #[Autowire('%kernel.environment%')] private string $kernelEnvironment,
    ) {
    }

    /** @return array<string, mixed> */
    public function create(Exercise $exercise, Request $request): array
    {
        $environment = $this->environments->get($exercise->environment);
        // Un exercice de Pratique n'a ni suite ni fiche : il dit d'où vient la fonctionnalité.
        $practice = null === $exercise->trackId ? $this->content->findPractice($exercise->id, $exercise->locale) : null;
        // Les pages de nouveautés sont en français : une traduction n'y renvoie pas.
        $translated = Exercise::LOCALE !== $exercise->locale;

        $payload = [
            'id' => $exercise->id,
            'trackId' => $exercise->trackId,
            'title' => $exercise->title,
            'concepts' => $exercise->concepts,
            // Les notions qui ont leur page, dans la langue de l'exercice : les pastilles du volet Consignes y mènent (voir ConceptIndex).
            'conceptUrls' => (object) $this->conceptUrls($exercise->concepts, $exercise->locale),
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
                // Extensions chargées en plus par le PHP du navigateur (intl, pour EasyAdmin) : les autres environnements
                // n'en téléchargent pas les données.
                'phpExtensions' => $environment->phpExtensions,
                // Ce que le navigateur sait du framework vient d'ici : console, dossiers du projet,
                // caches, namespaces… Il ne redéclare plus rien de son côté (voir FrameworkProfile). Ses textes
                // (note de démarrage, étapes) sont traduits dans la langue de la page.
                'framework' => $environment->framework->forBrowser($this->translator),
                'archiveUrl' => $this->assets->url($environment->archivePath(), $request),
                'completionIndexUrl' => $this->assets->url($environment->completionIndexPath(), $request),
                // Les paquets que `composer require` sait poser dans la console (projet sans framework), s'ils ont été construits.
                'packagesUrl' => null === ($catalogue = $environment->packageCatalogPath()) ? null : $this->assets->builtUrl($catalogue, $request),
            ],
            ...$this->navigation->of($exercise),
            'practice' => $practice ? [
                'framework' => $practice->framework,
                'version' => $practice->version,
                // Le résumé de la page publique : sous le titre, il dit ce que la fonctionnalité apporte.
                'summary' => $practice->summary,
                // Les autres exercices de cette version, quand ils sont assez nombreux pour avoir une page.
                'versionUrl' => $translated || null === ($slug = $this->versions->slugOf($practice)) ? null : $this->urls->generate('app_practice_version', ['slug' => $slug]),
                'pullRequest' => $practice->pullRequest,
                'published' => $practice->published->format('Y-m-d'),
                'track' => $this->navigation->practiceTrack($practice, $exercise->locale),
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
    private function conceptUrls(array $concepts, string $locale): array
    {
        $pages = array_column($this->concepts->all($locale), 'slug', 'slug');
        $urls = [];
        foreach ($concepts as $concept) {
            if (isset($pages[$slug = ConceptIndex::slug($concept, $locale)])) {
                $urls[$concept] = $this->urls->generate('app_concept', ['_locale' => $locale, 'slug' => $slug]);
            }
        }

        return $urls;
    }
}
