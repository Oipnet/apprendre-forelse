<?php

namespace App\Content;

use App\Version;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Charge les packs de contenu depuis le disque (CONTENT_PACKS_PATHS) et valide leur structure.
 *
 * Structure d'un pack :
 *   <pack>/pack.yaml
 *   <pack>/tracks/<parcours>/track.yaml
 *   <pack>/tracks/<parcours>/chapters/<chapitre>/lesson.md      fiche de cours de fin de chapitre (facultative)
 *   <pack>/tracks/<parcours>/exercises/<exercice>/{exercise.yaml, instructions.md, starter/, tests/, solution/}
 *   <pack>/practice/<exercice>/{exercise.yaml, …}                 exercice de Pratique, hors parcours (voir Practice)
 *   <pack>/versions/<version>.md                                  intro d'une page de nouveautés (facultative)
 *   <pack>/articles/<slug>.md                                     article du blog (voir Article)
 *
 * Les vérifications qui demandent d'exécuter le contenu (tests rouges puis verts)
 * sont faites par la commande content:check.
 *
 * Façade du contenu : la lecture et la validation sont dans PackReader et ExerciseReader, le cache dans PackLoader,
 * la navigation dans ContentIndex, les fichiers d'un exercice dans ExerciseSources. L'atelier, qui écrit dans les
 * packs, vide le cache par reset().
 */
final class ContentRepository
{
    /** Nom d'un fichier de DOWNLOADS_DIR : celui qu'accepte la route /telechargements/{fichier}. */
    public const string DOWNLOAD_NAME = '[A-Za-z0-9._-]+';

    /** Identifiant réservé : un parcours ne peut pas s'appeler ainsi (voir les routes /atelier/pratique/…). */
    public const string PRACTICE = 'pratique';

    /** Identifiant réservé : un exercice de parcours ne peut pas s'appeler ainsi (voir /parcours/{parcours}/acheter). */
    public const string PURCHASE = 'acheter';

    private readonly PackLoader $loader;
    private ?LoadedContent $content = null;
    private ?ContentIndex $index = null;
    private ?ExerciseSources $sources = null;

    /**
     * @param list<string> $packPaths dossiers de packs, ou dossiers contenant des packs
     */
    public function __construct(
        #[Autowire(env: 'csv:resolve:CONTENT_PACKS_PATHS')]
        array $packPaths,
        EnvironmentRegistry $environments,
        Version $version,
        /** Sans pool (tests, outils), le contenu est relu à chaque instance. */
        #[Autowire(service: 'content.cache')]
        ?CacheItemPoolInterface $cache = null,
    ) {
        $this->loader = new PackLoader($packPaths, $environments, $version, $cache);
    }

    /** Oublie ce qui a été lu, cache compris : à appeler après avoir écrit dans un pack (voir l'atelier). */
    public function reset(): void
    {
        $this->content = null;
        $this->index = null;
        $this->sources = null;
        $this->loader->forget();
    }

    /** @return array<string, Pack> */
    public function packs(): array
    {
        return $this->content()->packs;
    }

    /**
     * Clés du format encore acceptées mais dépréciées, par « parcours/exercice » : content:check les signale.
     *
     * @return array<string, string>
     */
    public function deprecations(): array
    {
        return $this->content()->deprecations;
    }

    /** @return array<string, Track> */
    public function tracks(): array
    {
        return $this->content()->tracks;
    }

    /** @return list<Track> les parcours qui déclarent ce fichier à télécharger (clé « downloads ») */
    public function tracksOffering(string $download): array
    {
        return array_values(array_filter($this->tracks(), static fn (Track $track) => \in_array($download, $track->downloads, true)));
    }

    public function findTrack(string $trackId): ?Track
    {
        return $this->tracks()[$trackId] ?? null;
    }

    /**
     * Le parcours conseillé après celui-ci. Un parcours d'un autre pack peut être cité :
     * s'il n'est pas installé (auto-hébergement avec le seul pack de démo…), on l'ignore.
     */
    public function nextTrack(Track $track): ?Track
    {
        return null === $track->next || $track->next === $track->id ? null : $this->findTrack($track->next);
    }

    public function findExercise(string $trackId, string $exerciseId): ?Exercise
    {
        return $this->index()->findExercise($trackId, $exerciseId);
    }

    /** @return array<string, Practice> les exercices de Pratique, du plus récent au plus ancien */
    public function practices(): array
    {
        return $this->content()->practices;
    }

    /**
     * Les intros écrites pour les pages de nouveautés, par version (« symfony-8-2 »). Sans intro, la page
     * compose son texte avec les notions de ses exercices (voir PracticeVersionIndex).
     *
     * @return array<string, array{markdown: string, file: string, packId: string}>
     */
    public function versionIntros(): array
    {
        return $this->content()->versionIntros;
    }

    /** @return array<string, Article> les articles du blog, du plus récent au plus ancien, publiés ou non */
    public function articles(): array
    {
        return $this->content()->articles;
    }

    public function findArticle(string $slug): ?Article
    {
        return $this->articles()[$slug] ?? null;
    }

    public function findPractice(string $id): ?Practice
    {
        return $this->practices()[$id] ?? null;
    }

    /** @return list<Exercise> dans l'ordre du parcours */
    public function exercisesOf(Track $track): array
    {
        return array_values($this->content()->exercises[$track->id] ?? []);
    }

    /**
     * Les exercices d'un chapitre (de tout le parcours sans chapitre), dans l'ordre de track.yaml ; un identifiant
     * sans exercice lisible est ignoré.
     *
     * @return list<Exercise>
     */
    public function exercisesOfChapter(Track $track, ?Chapter $chapter = null): array
    {
        return array_values(array_filter(array_map(
            fn (string $id) => $this->findExercise($track->id, $id),
            null === $chapter ? $track->exerciseIds() : $chapter->exerciseIds,
        )));
    }

    /**
     * Les exercices désignés par un pack, un parcours, « parcours/exercice », « pratique » ou « pratique/exercice »
     * (tous si null), éventuellement limités à un chapitre (utile pour répartir une vérification en parallèle).
     * Un pack comprend ses exercices de Pratique ; un chapitre les exclut.
     *
     * @return list<Exercise>
     */
    public function select(?string $target, ?string $chapterId = null): array
    {
        return $this->index()->select($target, $chapterId);
    }

    /**
     * Durée estimée d'un parcours, ou d'un de ses chapitres, en minutes : la somme de celles de ses exercices.
     * Null dès qu'un exercice n'en a pas : une somme partielle promettrait moins de temps qu'il n'en faut.
     */
    public function durationOf(Track $track, ?Chapter $chapter = null): ?int
    {
        return $this->index()->durationOf($track, $chapter);
    }

    public function findChapter(Track $track, string $chapterId): ?Chapter
    {
        return $this->index()->findChapter($track, $chapterId);
    }

    /**
     * Le chapitre d'un exercice. Appelé pour chaque exercice affiché, et en boucle (import de la progression d'un
     * invité) : un index, construit une fois depuis les parcours chargés, plutôt qu'un parcours de tout le contenu.
     */
    public function chapterOf(Exercise $exercise): ?Chapter
    {
        return $this->index()->chapterOf($exercise);
    }

    /** L'exercice qui clôt un chapitre est le dernier de sa liste. */
    public function closesChapter(Exercise $exercise): bool
    {
        return $this->index()->closesChapter($exercise);
    }

    /**
     * Les chapitres auxquels appartiennent ces exercices, dans l'ordre des parcours.
     *
     * @param list<Exercise> $exercises
     *
     * @return list<array{Track, Chapter}>
     */
    public function chaptersOf(array $exercises): array
    {
        return $this->index()->chaptersOf($exercises);
    }

    /** @return list<string> les identifiants de chapitres, tous parcours confondus */
    public function chapterIds(): array
    {
        return $this->index()->chapterIds();
    }

    public function next(Exercise $exercise): ?Exercise
    {
        return $this->index()->next($exercise);
    }

    public function previous(Exercise $exercise): ?Exercise
    {
        return $this->index()->previous($exercise);
    }

    /**
     * État de départ de l'exercice : état final de l'exercice `base` (s'il y en a un), puis starter/.
     *
     * @return array<string, string> contenu par chemin relatif au projet
     */
    public function startingFiles(Exercise $exercise): array
    {
        return $this->sources()->startingFiles($exercise);
    }

    /** @return array<string, string> état de départ + solution */
    public function solvedFiles(Exercise $exercise): array
    {
        return $this->sources()->solvedFiles($exercise);
    }

    /** @return array<string, string> fichiers de solution seuls (jamais envoyés au navigateur) */
    public function solutionFiles(Exercise $exercise): array
    {
        return $this->sources()->solutionFiles($exercise);
    }

    /** @return array<string, string> tests cachés, écrits dans tests/ du projet */
    public function testFiles(Exercise $exercise): array
    {
        return $this->sources()->testFiles($exercise);
    }

    /** L'identifiant actuel du parcours qui portait autrefois cet identifiant (clé « former_ids »), ou null. */
    public function currentTrackId(string $formerId): ?string
    {
        foreach ($this->content()->tracks as $track) {
            if (\in_array($formerId, $track->formerIds, true)) {
                return $track->id;
            }
        }

        return null;
    }

    /** L'identifiant actuel de l'exercice du parcours qui portait autrefois cet identifiant, ou null. */
    public function currentExerciseId(string $trackId, string $formerId): ?string
    {
        foreach ($this->content()->exercises[$trackId] ?? [] as $exercise) {
            if (\in_array($formerId, $exercise->formerIds, true)) {
                return $exercise->id;
            }
        }

        return null;
    }

    /** L'identifiant actuel de l'exercice de Pratique qui portait autrefois cet identifiant, ou null. */
    public function currentPracticeId(string $formerId): ?string
    {
        foreach ($this->content()->practices as $practice) {
            if (\in_array($formerId, $practice->exercise->formerIds, true)) {
                return $practice->exercise->id;
            }
        }

        return null;
    }

    private function content(): LoadedContent
    {
        return $this->content ??= $this->loader->load();
    }

    private function index(): ContentIndex
    {
        return $this->index ??= new ContentIndex($this->content());
    }

    private function sources(): ExerciseSources
    {
        return $this->sources ??= new ExerciseSources($this->index());
    }
}
