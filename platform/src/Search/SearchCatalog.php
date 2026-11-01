<?php

namespace App\Search;

use App\Content\Article;
use App\Content\ConceptIndex;
use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Content\Framework\FrameworkRegistry;
use App\Content\LessonRenderer;
use App\Content\PublishedContent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Les pages que la recherche du site connaît, dans une langue : ce qu'un visiteur sans compte peut lire, comme le
 * sitemap (voir PublishedContent). Exercices de Pratique parus, articles parus, notions, parcours publiés, leurs
 * chapitres et leurs exercices. Rien d'un parcours en préparation, d'un exercice ou d'un article programmé.
 *
 * Les indices et les solutions n'y entrent pas : la recherche ne doit pas donner la réponse d'un exercice.
 */
final readonly class SearchCatalog
{
    /** Le texte d'une page au-delà duquel on ne cherche plus : le début dit de quoi elle parle. */
    public const int TEXT_LIMIT = 6000;

    public function __construct(
        private ContentRepository $content,
        private PublishedContent $published,
        private ConceptIndex $concepts,
        private FrameworkRegistry $frameworks,
        private LessonRenderer $markdown,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /** @return list<string> les langues qui ont un index : celle des packs, puis les traductions */
    public function locales(): array
    {
        return array_values(array_unique([Exercise::LOCALE, ...Exercise::TRANSLATIONS, Article::LOCALE, ...Article::TRANSLATIONS]));
    }

    /** @return list<SearchDocument> */
    public function documents(string $locale): array
    {
        $documents = [];

        foreach ($this->published->practices($locale) as $practice) {
            $exercise = $practice->exercise;
            $documents[] = new SearchDocument(
                SearchDocument::PRACTICE,
                $exercise->id,
                $exercise->title,
                $this->path('app_exercise_pratique', ['_locale' => $locale, 'exerciseId' => $exercise->id]),
                $this->frameworks->labelOf($practice->framework).(null !== $practice->version ? ' '.$practice->version : ''),
                $practice->summary,
                $this->text($exercise->instructions),
                array_values($exercise->concepts),
            );
        }

        foreach ($this->published->articles($locale) as $article) {
            $documents[] = new SearchDocument(
                SearchDocument::ARTICLE,
                $article->slug,
                $article->title,
                $this->path('app_article', ['_locale' => $locale, 'slug' => $article->slug]),
                implode(', ', $article->tags),
                $article->description,
                $this->text($article->markdown),
                array_values($article->tags),
            );
        }

        $intros = $this->content->conceptIntros($locale);
        foreach ($this->concepts->all($locale) as $concept) {
            $intro = $intros[$concept['slug']] ?? null;
            $documents[] = new SearchDocument(
                SearchDocument::CONCEPT,
                $concept['slug'],
                $intro['title'] ?? $concept['name'],
                $this->path('app_concept', ['_locale' => $locale, 'slug' => $concept['slug']]),
                '',
                '',
                null !== $intro ? $this->text($intro['markdown']) : '',
                [$concept['name']],
            );
        }

        foreach ($this->published->tracks() as $published) {
            $track = $this->content->findTrack($published->id, $locale);
            if (null === $track) {
                continue;
            }
            $documents[] = new SearchDocument(
                SearchDocument::TRACK,
                $track->id,
                $track->title,
                $this->path('app_track', ['_locale' => $locale, 'trackId' => $track->id]),
                '',
                $track->description,
                null !== $track->about ? $this->text($track->about) : '',
            );
            foreach ($track->chapters as $chapter) {
                $exercises = $this->content->exercisesOfChapter($track, $chapter);
                // Un chapitre sans exercice lisible n'a pas de sommaire (voir ChapterOutline).
                if ([] === $exercises) {
                    continue;
                }
                $documents[] = new SearchDocument(
                    SearchDocument::CHAPTER,
                    $track->id.'/'.$chapter->id,
                    $chapter->title,
                    $this->path('app_chapter_summary', ['_locale' => $locale, 'trackId' => $track->id, 'chapterId' => $chapter->id]),
                    $track->title,
                    $chapter->description ?? '',
                    implode(' · ', array_map(static fn (Exercise $exercise) => $exercise->title, $exercises)),
                );
                foreach ($exercises as $exercise) {
                    $documents[] = new SearchDocument(
                        SearchDocument::EXERCISE,
                        $track->id.'/'.$exercise->id,
                        $exercise->title,
                        $this->path('app_exercise', ['_locale' => $locale, 'trackId' => $track->id, 'exerciseId' => $exercise->id]),
                        $track->title.' · '.$chapter->title,
                        '',
                        $this->text($exercise->instructions),
                        array_values($exercise->concepts),
                    );
                }
            }
        }

        return $documents;
    }

    /** Du Markdown au texte brut : ce qu'un lecteur voit, code compris (on cherche aussi « MapRequestPayload »). */
    private function text(string $markdown): string
    {
        $html = $this->markdown->toHtml($markdown);
        // Un bloc qui se ferme sépare deux mots : « </p><p> » ne doit pas les coller.
        $html = (string) preg_replace('#</(p|li|h[1-6]|pre|td|th|blockquote)>#i', "$0\n", $html);
        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, self::TEXT_LIMIT);
    }

    /** @param array<string, string> $parameters */
    private function path(string $route, array $parameters): string
    {
        return $this->urls->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_PATH);
    }
}
