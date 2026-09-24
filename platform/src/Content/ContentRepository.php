<?php

namespace App\Content;

use App\Version;
use Composer\Semver\Semver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

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
 *
 * Les vérifications qui demandent d'exécuter le contenu (tests rouges puis verts)
 * sont faites par la commande content:check.
 */
final class ContentRepository
{
    /** Nom d'un fichier de DOWNLOADS_DIR : celui qu'accepte la route /telechargements/{fichier}. */
    public const string DOWNLOAD_NAME = '[A-Za-z0-9._-]+';

    /** @var array<string, Pack>|null */
    private ?array $packs = null;
    /** @var array<string, Track> */
    private array $tracks = [];
    /** @var array<string, array<string, Exercise>> exercices par parcours, dans l'ordre */
    private array $exercises = [];
    /** @var array<string, Practice> exercices de Pratique, du plus récent au plus ancien */
    private array $practices = [];
    /** @var array<string, string> clés dépréciées rencontrées, par « parcours/exercice » (voir deprecations()) */
    private array $deprecations = [];
    /** @var array<string, array{markdown: string, file: string, packId: string}> intros de pages de nouveautés, par version */
    private array $versionIntros = [];

    /** Identifiant réservé : un parcours ne peut pas s'appeler ainsi (voir les routes /atelier/pratique/…). */
    public const string PRACTICE = 'pratique';

    /** Identifiant réservé : un exercice de parcours ne peut pas s'appeler ainsi (voir /parcours/{parcours}/acheter). */
    public const string PURCHASE = 'acheter';

    /**
     * @param list<string> $packPaths dossiers de packs, ou dossiers contenant des packs
     */
    public function __construct(
        #[Autowire(env: 'csv:resolve:CONTENT_PACKS_PATHS')]
        private readonly array $packPaths,
        private readonly EnvironmentRegistry $environments,
        private readonly Version $version,
    ) {
    }

    /** Oublie ce qui a été lu : à appeler après avoir écrit dans un pack (voir l'atelier). */
    public function reset(): void
    {
        $this->packs = null;
        $this->tracks = [];
        $this->exercises = [];
        $this->practices = [];
        $this->versionIntros = [];
        $this->deprecations = [];
    }

    /** @return array<string, Pack> */
    public function packs(): array
    {
        return $this->load();
    }

    /**
     * Clés du format encore acceptées mais dépréciées, par « parcours/exercice » : content:check les signale.
     *
     * @return array<string, string>
     */
    public function deprecations(): array
    {
        $this->load();

        return $this->deprecations;
    }

    /** @return array<string, Track> */
    public function tracks(): array
    {
        $this->load();

        return $this->tracks;
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
        $this->load();

        return $this->exercises[$trackId][$exerciseId] ?? null;
    }

    /** @return array<string, Practice> les exercices de Pratique, du plus récent au plus ancien */
    public function practices(): array
    {
        $this->load();

        return $this->practices;
    }

    /**
     * Les intros écrites pour les pages de nouveautés, par version (« symfony-8-2 »). Sans intro, la page
     * compose son texte avec les notions de ses exercices (voir PracticeVersionIndex).
     *
     * @return array<string, array{markdown: string, file: string, packId: string}>
     */
    public function versionIntros(): array
    {
        $this->load();

        return $this->versionIntros;
    }

    public function findPractice(string $id): ?Practice
    {
        return $this->practices()[$id] ?? null;
    }

    /** @return list<Exercise> dans l'ordre du parcours */
    public function exercisesOf(Track $track): array
    {
        $this->load();

        return array_values($this->exercises[$track->id] ?? []);
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
        $exercises = [];
        foreach ($this->tracks() as $track) {
            foreach ($track->chapters as $chapter) {
                if (null !== $chapterId && $chapter->id !== $chapterId) {
                    continue;
                }
                foreach ($chapter->exerciseIds as $exerciseId) {
                    $exercise = $this->findExercise($track->id, $exerciseId);
                    if (null !== $exercise && (null === $target || \in_array($target, [$track->packId, $track->id, $track->id.'/'.$exercise->id], true))) {
                        $exercises[] = $exercise;
                    }
                }
            }
        }
        if (null !== $chapterId) {
            return $exercises;
        }
        foreach ($this->practices() as $practice) {
            if (null === $target || \in_array($target, [$practice->packId, self::PRACTICE, self::PRACTICE.'/'.$practice->exercise->id], true)) {
                $exercises[] = $practice->exercise;
            }
        }

        return $exercises;
    }

    /**
     * Durée estimée d'un parcours, ou d'un de ses chapitres, en minutes : la somme de celles de ses exercices.
     * Null dès qu'un exercice n'en a pas : une somme partielle promettrait moins de temps qu'il n'en faut.
     */
    public function durationOf(Track $track, ?Chapter $chapter = null): ?int
    {
        $total = 0;
        foreach ($chapter?->exerciseIds ?? $track->exerciseIds() as $exerciseId) {
            $duration = $this->findExercise($track->id, $exerciseId)?->duration;
            if (null === $duration) {
                return null;
            }
            $total += $duration;
        }

        return $total > 0 ? $total : null;
    }

    public function findChapter(Track $track, string $chapterId): ?Chapter
    {
        foreach ($track->chapters as $chapter) {
            if ($chapter->id === $chapterId) {
                return $chapter;
            }
        }

        return null;
    }

    /** Le chapitre d'un exercice. */
    public function chapterOf(Exercise $exercise): ?Chapter
    {
        return $this->chaptersOf([$exercise])[0][1] ?? null;
    }

    /** L'exercice qui clôt un chapitre est le dernier de sa liste. */
    public function closesChapter(Exercise $exercise): bool
    {
        $chapter = $this->chapterOf($exercise);

        return null !== $chapter && $exercise->id === ($chapter->exerciseIds[\count($chapter->exerciseIds) - 1] ?? null);
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
        $ids = array_map(static fn (Exercise $e) => $e->trackId.'/'.$e->id, array_filter($exercises, static fn (Exercise $e) => null !== $e->trackId));
        $chapters = [];
        foreach ($this->tracks() as $track) {
            foreach ($track->chapters as $chapter) {
                foreach ($chapter->exerciseIds as $exerciseId) {
                    if (\in_array($track->id.'/'.$exerciseId, $ids, true)) {
                        $chapters[] = [$track, $chapter];
                        break;
                    }
                }
            }
        }

        return $chapters;
    }

    /** @return list<string> les identifiants de chapitres, tous parcours confondus */
    public function chapterIds(): array
    {
        $ids = [];
        foreach ($this->tracks() as $track) {
            foreach ($track->chapters as $chapter) {
                $ids[] = $chapter->id;
            }
        }

        return $ids;
    }

    public function next(Exercise $exercise): ?Exercise
    {
        if (null === $exercise->trackId) {
            return null;
        }
        $ids = array_keys($this->exercises[$exercise->trackId] ?? []);
        $position = array_search($exercise->id, $ids, true);

        return false === $position ? null : $this->findExercise($exercise->trackId, $ids[$position + 1] ?? '');
    }

    /**
     * État de départ de l'exercice : état final de l'exercice `base` (s'il y en a un), puis starter/.
     *
     * @return array<string, string> contenu par chemin relatif au projet
     */
    public function startingFiles(Exercise $exercise): array
    {
        $files = null === $exercise->base ? [] : $this->solvedFiles($this->baseOf($exercise));

        return [...$files, ...$this->readDirectory($exercise->directory.'/starter')];
    }

    /** @return array<string, string> état de départ + solution */
    public function solvedFiles(Exercise $exercise): array
    {
        return [...$this->startingFiles($exercise), ...$this->solutionFiles($exercise)];
    }

    /** @return array<string, string> fichiers de solution seuls (jamais envoyés au navigateur) */
    public function solutionFiles(Exercise $exercise): array
    {
        return $this->readDirectory($exercise->directory.'/solution');
    }

    /** @return array<string, string> tests cachés, écrits dans tests/ du projet */
    public function testFiles(Exercise $exercise): array
    {
        $tests = [];
        foreach ($this->readDirectory($exercise->directory.'/tests') as $path => $content) {
            $tests['tests/'.$path] = $content;
        }

        return $tests;
    }

    private function baseOf(Exercise $exercise): Exercise
    {
        return (null === $exercise->trackId ? null : $this->findExercise($exercise->trackId, (string) $exercise->base))
            ?? throw new ContentException(sprintf('Exercice « %s » : base « %s » introuvable.', $exercise->id, $exercise->base));
    }

    /** @return array<string, Pack> */
    private function load(): array
    {
        if (null !== $this->packs) {
            return $this->packs;
        }

        $this->packs = [];
        foreach ($this->packPaths as $path) {
            $path = rtrim(trim($path), '/');
            if ('' === $path) {
                continue;
            }
            if (!is_dir($path)) {
                throw new ContentException(sprintf('Dossier de packs introuvable : %s (voir CONTENT_PACKS_PATHS).', $path));
            }
            // Un chemin peut désigner un pack, ou un dossier contenant plusieurs packs.
            $directories = is_file($path.'/pack.yaml') ? [$path] : glob($path.'/*', \GLOB_ONLYDIR);
            foreach ($directories as $directory) {
                if (is_file($directory.'/pack.yaml')) {
                    $this->loadPack($directory);
                }
            }
        }

        // L'ordre de chargement dépend des chemins configurés et du nom des dossiers : il ne doit pas décider
        // de l'accueil. Les parcours qui ont un rang passent devant ; les autres gardent l'ordre de chargement
        // (uasort est stable, comme toutes les fonctions de tri depuis PHP 8.0).
        uasort($this->tracks, static fn (Track $a, Track $b) => [null === $a->order, $a->order] <=> [null === $b->order, $b->order]);
        uasort($this->practices, static fn (Practice $a, Practice $b) => [$b->published, $a->exercise->id] <=> [$a->published, $b->exercise->id]);

        return $this->packs;
    }

    private function loadPack(string $directory): void
    {
        $meta = $this->parse($directory.'/pack.yaml');
        $id = $this->required($meta, 'id', $directory.'/pack.yaml');
        if (isset($this->packs[$id])) {
            throw new ContentException(sprintf('Pack « %s » déclaré deux fois (%s et %s).', $id, $this->packs[$id]->directory, $directory));
        }

        $practiceDirectories = glob($directory.'/practice/*', \GLOB_ONLYDIR) ?: [];
        $pack = new Pack(
            id: $id,
            title: $this->required($meta, 'title', $directory.'/pack.yaml'),
            description: $meta['description'] ?? '',
            version: (string) ($meta['version'] ?? '0.0.0'),
            license: $meta['license'] ?? 'proprietary',
            trackIds: $meta['tracks'] ?? [],
            directory: $directory,
            engine: isset($meta['moteur']) ? (string) $meta['moteur'] : null,
            practiceIds: array_map('basename', $practiceDirectories),
        );
        $this->checkEngine($pack, $directory.'/pack.yaml');
        $this->packs[$id] = $pack;

        foreach ($pack->trackIds as $trackId) {
            $this->loadTrack($pack, $directory.'/tracks/'.$trackId);
        }
        foreach ($practiceDirectories as $practiceDirectory) {
            $this->loadPractice($pack, $practiceDirectory);
        }
        foreach (glob($directory.'/versions/*.md') ?: [] as $file) {
            $this->loadVersionIntro($pack, $file);
        }
    }

    /**
     * L'intro d'une page de nouveautés : `<pack>/versions/symfony-8-2.md`, du Markdown sans titre (la page
     * affiche le sien). Le nom du fichier est l'identifiant de la version dans l'adresse, tel qu'il s'écrit
     * (voir PracticeVersionIndex::slug) : une faute de frappe donnerait une intro que rien n'affiche, alors
     * elle est refusée ici, et content:check signale une intro qui ne correspond à aucune page.
     */
    private function loadVersionIntro(Pack $pack, string $file): void
    {
        $slug = basename($file, '.md');
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)) {
            throw new ContentException(sprintf('Intro de version « %s » : le nom du fichier doit être l\'identifiant de la version dans l\'adresse, en minuscules (« symfony-8-2.md »).', $file));
        }
        if (isset($this->versionIntros[$slug])) {
            throw new ContentException(sprintf('Intro de version « %s » présente deux fois (packs %s et %s).', $slug, $this->versionIntros[$slug]['packId'], $pack->id));
        }
        $markdown = trim((string) file_get_contents($file));
        if ('' === $markdown) {
            throw new ContentException(sprintf('Intro de version « %s » vide : une page sans intro écrite compose son texte toute seule, autant retirer le fichier.', $file));
        }

        $this->versionIntros[$slug] = ['markdown' => $markdown, 'file' => $file, 'packId' => $pack->id];
    }


    /**
     * Un pack peut exiger une version du moteur (clé « moteur » de pack.yaml, syntaxe Composer).
     * Le contrat est le format de pack : voir la section « Versionnage » du README.
     */
    private function checkEngine(Pack $pack, string $file): void
    {
        if (null === $pack->engine) {
            return;
        }

        $moteur = $this->version->get();
        try {
            // Version inconnue (fichier VERSION absent) : on valide la syntaxe, sans bloquer le pack.
            $ok = Version::INCONNUE === $moteur || Semver::satisfies($moteur, $pack->engine);
        } catch (\UnexpectedValueException $e) {
            throw new ContentException(sprintf('%s : contrainte « moteur: %s » illisible (syntaxe de Composer : ^1.2, ~1.2.3, >=1.2 <2.0).', $file, $pack->engine), previous: $e);
        }

        if (!$ok) {
            throw new ContentException(sprintf(
                'Le pack « %s » demande un moteur %s, or celui-ci est en %s : mettez le moteur à jour, ou installez une version du pack prévue pour cette version.',
                $pack->id,
                $pack->engine,
                $moteur,
            ));
        }
    }

    private function loadTrack(Pack $pack, string $directory): void
    {
        $file = $directory.'/track.yaml';
        $meta = $this->parse($file);
        $id = $this->required($meta, 'id', $file);
        if (basename($directory) !== $id) {
            throw new ContentException(sprintf('%s : l\'id « %s » doit correspondre au nom du dossier.', $file, $id));
        }
        if (isset($this->tracks[$id])) {
            throw new ContentException(sprintf('Parcours « %s » présent dans deux packs (%s et %s).', $id, $this->tracks[$id]->packId, $pack->id));
        }
        if (self::PRACTICE === $id) {
            throw new ContentException(sprintf('%s : « %s » est réservé aux exercices de Pratique, choisissez un autre id de parcours.', $file, $id));
        }

        $environment = $this->required($meta, 'environment', $file);
        $this->environments->get($environment); // échoue tôt si l'environnement n'existe pas

        $chapters = [];
        foreach ($meta['chapters'] ?? [] as $chapter) {
            if (isset($chapter['environment'])) {
                $this->environments->get($chapter['environment']);
            }
            $chapterId = $this->required($chapter, 'id', $file);
            if (\in_array($chapterId, array_map(static fn (Chapter $c) => $c->id, $chapters), true)) {
                throw new ContentException(sprintf('Parcours « %s » : chapitre « %s » déclaré deux fois.', $id, $chapterId));
            }
            $lesson = $directory.'/chapters/'.$chapterId.'/lesson.md';
            $chapters[] = new Chapter(
                id: $chapterId,
                title: $this->required($chapter, 'title', $file),
                exerciseIds: $chapter['exercises'] ?? [],
                environment: $chapter['environment'] ?? null,
                lesson: is_file($lesson) ? (string) file_get_contents($lesson) : null,
            );
        }
        if (!$chapters) {
            throw new ContentException(sprintf('%s : au moins un chapitre est requis (clé « chapters »).', $file));
        }

        $visibility = (string) ($meta['visibility'] ?? Track::VISIBILITY_PUBLIC);
        if (!\in_array($visibility, Track::VISIBILITIES, true)) {
            throw new ContentException(sprintf('%s : visibilité « %s » inconnue (%s).', $file, $visibility, implode(', ', Track::VISIBILITIES)));
        }

        $order = $meta['order'] ?? null;
        if (null !== $order && !\is_int($order)) {
            throw new ContentException(sprintf('%s : « order » doit être un nombre entier (le plus petit s\'affiche en premier).', $file));
        }

        $downloads = $meta['downloads'] ?? [];
        if (!\is_array($downloads) || !array_is_list($downloads) || [] !== array_filter($downloads, static fn ($name) => !\is_string($name) || 1 !== preg_match('/^'.self::DOWNLOAD_NAME.'$/', $name))) {
            throw new ContentException(sprintf('%s : « downloads » est une liste de noms de fichiers de DOWNLOADS_DIR (lettres, chiffres, « . », « _ », « - »).', $file));
        }

        $track = new Track($id, $pack->id, $this->required($meta, 'title', $file), $meta['description'] ?? '', $environment, $chapters, $directory, $meta['next'] ?? null, $visibility, $order, $downloads);
        $this->tracks[$id] = $track;
        $this->exercises[$id] = [];

        foreach ($track->chapters as $chapter) {
            foreach ($chapter->exerciseIds as $exerciseId) {
                if (self::PURCHASE === $exerciseId) {
                    throw new ContentException(sprintf('Parcours « %s » : « %s » est réservé à la page d\'achat du parcours, choisissez un autre id d\'exercice.', $id, $exerciseId));
                }
                if (isset($this->exercises[$id][$exerciseId])) {
                    throw new ContentException(sprintf('Parcours « %s » : exercice « %s » listé deux fois.', $id, $exerciseId));
                }
                $this->exercises[$id][$exerciseId] = $this->loadChapterExercise($track, $chapter, $directory.'/exercises/'.$exerciseId);
            }
        }
    }

    private function loadChapterExercise(Track $track, Chapter $chapter, string $directory): Exercise
    {
        $exercise = $this->loadExercise($directory, $track->id, $chapter->environment ?? $track->environment);
        // `base` doit désigner un exercice précédent : pas de cycle possible.
        if (null !== $exercise->base && !isset($this->exercises[$track->id][$exercise->base])) {
            throw new ContentException(sprintf('Exercice « %s » : la base « %s » doit être un exercice précédent du parcours.', $exercise->id, $exercise->base));
        }

        return $exercise;
    }

    private function loadPractice(Pack $pack, string $directory): void
    {
        $file = $directory.'/exercise.yaml';
        $meta = $this->parse($file);
        // Un exercice de Pratique se suffit à lui-même : pas de parcours dont hériter, rien à gagner, un compte requis.
        foreach (['access' => 'un compte est toujours demandé', 'base' => 'il ne part d\'aucun autre exercice', 'xp' => 'il ne rapporte pas d\'XP'] as $key => $why) {
            if (\array_key_exists($key, $meta)) {
                throw new ContentException(sprintf('%s : « %s » n\'a pas cours dans un exercice de Pratique (%s).', $file, $key, $why));
            }
        }
        if (!isset($meta['environment'])) {
            throw new ContentException(sprintf('%s : clé « environment » manquante (un exercice de Pratique n\'a pas de parcours dont l\'hériter).', $file));
        }

        $exercise = $this->loadExercise($directory, null, null, $meta);
        if (isset($this->practices[$exercise->id])) {
            throw new ContentException(sprintf('Exercice de Pratique « %s » présent deux fois (packs %s et %s).', $exercise->id, $this->practices[$exercise->id]->packId, $pack->id));
        }

        $published = $meta['published'] ?? null;
        // YAML lit une date sans guillemets (2026-09-20) comme un horodatage.
        $published = match (true) {
            \is_int($published) => new \DateTimeImmutable('@'.$published),
            \is_string($published) && false !== ($date = \DateTimeImmutable::createFromFormat('!Y-m-d', $published)) => $date,
            default => throw new ContentException(sprintf('%s : « published » doit être une date (AAAA-MM-JJ).', $file)),
        };

        $version = $meta['version'] ?? null;
        if (null !== $version && (!\is_string($version) || !preg_match('/^\d+(\.\d+){0,2}$/', $version))) {
            throw new ContentException(sprintf('%s : « version » s\'écrit entre guillemets, par exemple « version: \'8.1\' » (sans guillemets, YAML lit 8.10 comme 8.1).', $file));
        }

        $pullRequest = $meta['pull_request'] ?? null;
        if (null !== $pullRequest && (!\is_string($pullRequest) || !preg_match('#^https://[^\s"\'<>]+$#', $pullRequest))) {
            throw new ContentException(sprintf('%s : « pull_request » doit être une URL https.', $file));
        }

        $visibility = (string) ($meta['visibility'] ?? Track::VISIBILITY_PUBLIC);
        if (!\in_array($visibility, Track::VISIBILITIES, true)) {
            throw new ContentException(sprintf('%s : visibilité « %s » inconnue (%s).', $file, $visibility, implode(', ', Track::VISIBILITIES)));
        }

        $this->practices[$exercise->id] = new Practice(
            exercise: $exercise,
            packId: $pack->id,
            framework: $this->environments->get($exercise->environment)->framework->id,
            published: $published,
            summary: $this->required($meta, 'summary', $file),
            version: $version,
            pullRequest: $pullRequest,
            visibility: $visibility,
        );
    }

    /**
     * @param string|null               $trackId              null pour un exercice de Pratique
     * @param string|null               $inheritedEnvironment environnement du chapitre ou du parcours, faute de clé « environment »
     * @param array<string, mixed>|null $meta                 exercise.yaml déjà lu
     */
    private function loadExercise(string $directory, ?string $trackId, ?string $inheritedEnvironment, ?array $meta = null): Exercise
    {
        $file = $directory.'/exercise.yaml';
        $meta ??= $this->parse($file);
        $id = $this->required($meta, 'id', $file);
        if (null !== $trackId && \array_key_exists('access', $meta)) {
            // Depuis la 0.8.0, le moteur ouvre tout le premier chapitre d'un parcours à tout compte : la clé n'a plus d'effet.
            $this->deprecations[$trackId.'/'.$id] = '« access » est dépréciée et sans effet : le premier chapitre de chaque parcours est gratuit pour tout compte, et la suite dépend de l\'accès au parcours. Retirez la clé ; elle sera refusée dans une version majeure.';
        }
        if (basename($directory) !== $id) {
            throw new ContentException(sprintf('%s : l\'id « %s » doit correspondre au nom du dossier.', $file, $id));
        }
        if (!is_file($directory.'/instructions.md')) {
            throw new ContentException(sprintf('Exercice « %s » : instructions.md manquant.', $id));
        }

        $environment = (string) ($meta['environment'] ?? $inheritedEnvironment);
        $this->environments->get($environment);

        $mutants = [];
        foreach ($meta['mutants'] ?? [] as $mutant) {
            $mutant = $this->mutant($mutant, $file);
            if (isset($mutants[$mutant->id])) {
                throw new ContentException(sprintf('%s : mutant « %s » déclaré deux fois.', $file, $mutant->id));
            }
            $mutants[$mutant->id] = $mutant;
        }
        $objectives = array_map(function (array $o) use ($file, $mutants) {
            $label = $this->required($o, 'label', $file);
            if (isset($o['mutant'])) {
                return isset($mutants[$o['mutant']])
                    ? new Objective(Objective::MUTANT_PREFIX.$o['mutant'], $label)
                    : throw new ContentException(sprintf('%s : l\'objectif cite le mutant « %s », qui n\'est pas déclaré dans « mutants ».', $file, $o['mutant']));
            }

            return new Objective(isset($o['own-tests']) ? Objective::OWN_TESTS : $this->required($o, 'test', $file), $label);
        }, $meta['objectives'] ?? []);
        if (!$objectives) {
            throw new ContentException(sprintf('%s : au moins un objectif est requis.', $file));
        }

        $editable = $meta['editable'] ?? [];
        $readonly = $meta['readonly'] ?? [];
        // Un fichier à la fois modifiable et verrouillé serait ouvert deux fois dans l'éditeur.
        foreach ($readonly as $verrouille) {
            foreach ($editable as $entree) {
                if ($entree === $verrouille || (str_contains($entree, '*') && fnmatch($entree, $verrouille, \FNM_PATHNAME))) {
                    throw new ContentException(sprintf('%s : « %s » est à la fois éditable (%s) et en lecture seule.', $file, $verrouille, $entree));
                }
            }
        }
        // Un motif (migrations/*.php) désigne des fichiers à venir : il ne peut pas être ouvert.
        $explicites = array_values(array_filter($editable, static fn (string $entree) => !str_contains($entree, '*')));
        $open = $meta['open'] ?? ($explicites[0] ?? $readonly[0] ?? throw new ContentException(sprintf('%s : aucun fichier éditable ni en lecture seule à ouvrir (précisez « open »).', $file)));
        if (str_contains($open, '*')) {
            throw new ContentException(sprintf('%s : « open » (%s) doit désigner un fichier, pas un motif.', $file, $open));
        }
        // Un fichier déjà là, couvert par un motif (src/Entity/*.php), peut aussi s'ouvrir : le vérificateur contrôle qu'il existe.
        $couvertParUnMotif = array_filter($editable, static fn (string $entree) => str_contains($entree, '*') && fnmatch($entree, $open, \FNM_PATHNAME));
        if (!\in_array($open, [...$explicites, ...$readonly], true) && !$couvertParUnMotif) {
            throw new ContentException(sprintf('%s : « open » (%s) doit faire partie des fichiers éditables ou en lecture seule.', $file, $open));
        }

        $exercise = new Exercise(
            id: $id,
            trackId: $trackId,
            title: $this->required($meta, 'title', $file),
            // YAML lit « 404 » comme un entier : les étiquettes restent des chaînes.
            concepts: array_map('strval', $meta['concepts'] ?? []),
            xp: (int) ($meta['xp'] ?? 0),
            access: Access::tryFrom($meta['access'] ?? Access::Account->value)
                ?? throw new ContentException(sprintf('%s : « access » doit valoir %s.', $file, implode(' ou ', array_column(Access::cases(), 'value')))),
            environment: $environment,
            base: $meta['base'] ?? null,
            open: $open,
            preview: $meta['preview'] ?? '/',
            editable: $editable,
            readonly: $readonly,
            objectives: $objectives,
            hints: $meta['hints'] ?? [],
            instructions: (string) file_get_contents($directory.'/instructions.md'),
            directory: $directory,
            setup: array_map(
                static fn ($command) => \is_string($command) && '' !== trim($command) ? trim($command)
                    : throw new ContentException(sprintf('%s : chaque commande de « setup » est une chaîne (ex. « doctrine:schema:update --force »).', $file)),
                $meta['setup'] ?? [],
            ),
            requests: array_map(fn ($request) => $this->exampleRequest($request, $file), $meta['requests'] ?? []),
            docs: array_map(fn ($doc) => $this->docLink($doc, $file), $meta['docs'] ?? []),
            mutants: array_values($mutants),
            duration: $this->duration($meta['duration'] ?? null, $file),
        );
        if (($mutants || array_filter($objectives, static fn (Objective $o) => !$o->isHiddenTest())) && !$exercise->ownTests()) {
            throw new ContentException(sprintf('%s : des objectifs portent sur les tests de l\'apprenant, mais aucun fichier éditable tests/…Test.php ne les accueille.', $file));
        }

        return $exercise;
    }

    /** Durée estimée d'un exercice : des minutes, entre 1 et 600. */
    private function duration(mixed $duration, string $file): ?int
    {
        if (null === $duration) {
            return null;
        }
        if (!\is_int($duration) || $duration < 1 || $duration > 600) {
            throw new ContentException(sprintf('%s : « duration » est une durée estimée en minutes, un entier entre 1 et 600 (par exemple « duration: 20 »).', $file));
        }

        return $duration;
    }

    private function mutant(mixed $mutant, string $file): Mutant
    {
        if (!\is_array($mutant) || !\is_array($mutant['changes'] ?? null) || !$mutant['changes']) {
            throw new ContentException(sprintf('%s : chaque mutant a un id, un label et des « changes » ({file, search, replace}).', $file));
        }
        $changes = [];
        foreach ($mutant['changes'] as $change) {
            $changes[] = [
                'file' => $this->required($change, 'file', $file),
                'search' => $this->required($change, 'search', $file),
                'replace' => (string) ($change['replace'] ?? ''),
            ];
        }

        return new Mutant($this->required($mutant, 'id', $file), $this->required($mutant, 'label', $file), $changes);
    }

    private function docLink(mixed $doc, string $file): DocLink
    {
        if (!\is_array($doc)) {
            throw new ContentException(sprintf('%s : chaque entrée de « docs » est un objet {title, url}.', $file));
        }
        $url = $this->required($doc, 'url', $file);
        // Affiché comme lien dans la plateforme : pas de javascript:, data:…
        if (!preg_match('#^https?://[^\s"\'<>]+$#', $url)) {
            throw new ContentException(sprintf('%s : le lien de documentation « %s » doit être une URL http(s).', $file, $url));
        }

        return new DocLink($this->required($doc, 'title', $file), $url);
    }

    private function exampleRequest(mixed $request, string $file): ExampleRequest
    {
        if (!\is_array($request)) {
            throw new ContentException(sprintf('%s : chaque entrée de « requests » est un objet (method, path…).', $file));
        }
        $method = strtoupper((string) ($request['method'] ?? 'GET'));
        $path = (string) $this->required($request, 'path', $file);
        if (!\in_array($method, ExampleRequest::METHODS, true)) {
            throw new ContentException(sprintf('%s : méthode « %s » inconnue dans « requests » (%s).', $file, $method, implode(', ', ExampleRequest::METHODS)));
        }
        if (!str_starts_with($path, '/')) {
            throw new ContentException(sprintf('%s : le chemin « %s » de « requests » doit commencer par « / ».', $file, $path));
        }
        $body = $request['body'] ?? null;

        return new ExampleRequest(
            title: (string) ($request['title'] ?? $method.' '.$path),
            method: $method,
            path: $path,
            headers: array_map('strval', $request['headers'] ?? []),
            // Un objet YAML devient du JSON indenté ; une chaîne est envoyée telle quelle.
            body: \is_array($body) ? json_encode($body, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) : (null === $body ? null : (string) $body),
        );
    }

    /** @return array<string, string> */
    private function readDirectory(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $files = [];
        foreach ((new Finder())->files()->in($directory)->ignoreDotFiles(false)->sortByName() as $file) {
            $files[str_replace('\\', '/', $file->getRelativePathname())] = $file->getContents();
        }

        return $files;
    }

    /** @return array<string, mixed> */
    private function parse(string $file): array
    {
        if (!is_file($file)) {
            throw new ContentException(sprintf('Fichier manquant : %s', $file));
        }
        $data = Yaml::parseFile($file);
        if (!\is_array($data)) {
            throw new ContentException(sprintf('%s : contenu YAML invalide.', $file));
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function required(array $data, string $key, string $file): string
    {
        if (!isset($data[$key]) || '' === $data[$key]) {
            throw new ContentException(sprintf('%s : clé « %s » manquante.', $file, $key));
        }

        return (string) $data[$key];
    }
}
