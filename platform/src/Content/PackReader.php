<?php

namespace App\Content;

use App\Version;
use Composer\Semver\Semver;

/**
 * Un passage de lecture des packs (CONTENT_PACKS_PATHS) : packs, parcours, exercices de Pratique, intros de
 * versions et articles du blog, validés au fil de l'eau. Chaque lecture part d'une instance neuve (voir PackLoader).
 */
final class PackReader
{
    /** @var array<string, Pack> */
    private array $packs = [];
    /** @var array<string, Track> */
    private array $tracks = [];
    /** @var array<string, array<string, Exercise>> exercices par parcours, dans l'ordre */
    private array $exercises = [];
    /** @var array<string, Practice> */
    private array $practices = [];
    /** @var array<string, array<string, Practice>> traductions des exercices de Pratique, par langue puis par id */
    private array $practiceTranslations = [];
    /** @var array<string, array<string, Track>> traductions des parcours, par langue puis par id */
    private array $trackTranslations = [];
    /** @var array<string, array<string, array<string, Exercise>>> traductions des exercices de parcours, par langue, parcours puis id */
    private array $exerciseTranslations = [];
    /** @var array<string, list<string>> traductions en retard sur le français, par langue : « parcours » ou « parcours/exercice » */
    private array $outdatedTranslations = [];
    /** @var array<string, array<string, list<string>>> parcours dont la traduction est incomplète, par langue : ce qui manque */
    private array $incompleteTranslations = [];
    /** @var array<string, array{markdown: string, file: string, packId: string}> */
    private array $versionIntros = [];
    /** @var array<string, array{markdown: string, file: string, packId: string, title: string|null}> */
    private array $conceptIntros = [];
    /** @var array<string, array<string, array{markdown: string, file: string, packId: string, title: string|null}>> intros des notions traduites, par langue puis par notion */
    private array $conceptIntroTranslations = [];
    /** @var array<string, array<string, list<ConceptTheme>>> par langue, puis par framework */
    private array $conceptThemes = [];
    /** @var array<string, array<string, string>> par langue, framework => pack qui en range les notions */
    private array $conceptThemesPacks = [];
    /** @var array<string, array<string, Article>> par langue, puis par slug */
    private array $articles = [];
    private readonly PackFiles $files;
    private readonly ExerciseReader $exerciseReader;
    private readonly ArticleReader $articleReader;
    private readonly ConceptReader $conceptReader;

    /**
     * @param list<string> $packPaths dossiers de packs, ou dossiers contenant des packs
     */
    public function __construct(
        private readonly array $packPaths,
        private readonly EnvironmentRegistry $environments,
        private readonly Version $version,
    ) {
        $this->files = new PackFiles();
        $this->exerciseReader = new ExerciseReader($this->files, $environments);
        $this->articleReader = new ArticleReader($this->files);
        $this->conceptReader = new ConceptReader($this->files, $environments->frameworks());
    }

    public function read(): LoadedContent
    {
        // Un environnement installé ou retiré change ce que le chargement accepte.
        foreach ($this->environments->roots() as $root) {
            $this->files->watch($root);
        }
        foreach ($this->packPaths as $path) {
            $path = rtrim(trim($path), '/');
            if ('' === $path) {
                continue;
            }
            if (!is_dir($path)) {
                throw new ContentException(sprintf('Dossier de packs introuvable : %s (voir CONTENT_PACKS_PATHS).', $path));
            }
            // Un chemin peut désigner un pack, ou un dossier contenant plusieurs packs.
            $this->files->watch($path);
            $directories = is_file($path.'/pack.yaml') ? [$path] : glob($path.'/*', \GLOB_ONLYDIR);
            foreach ($directories as $directory) {
                if (is_file($directory.'/pack.yaml')) {
                    $this->loadPack($directory);
                }
            }
        }

        FormerIds::assertNoCollision(array_map(static fn (Track $track) => $track->formerIds, $this->tracks), 'Parcours');
        foreach ($this->exercises as $trackId => $exercises) {
            FormerIds::assertNoCollision(array_map(static fn (Exercise $exercise) => $exercise->formerIds, $exercises), sprintf('Parcours « %s », exercice', $trackId));
        }
        FormerIds::assertNoCollision(array_map(static fn (Practice $practice) => $practice->exercise->formerIds, $this->practices), 'Exercice de Pratique');

        // L'ordre de chargement dépend des chemins configurés et du nom des dossiers : il ne doit pas décider
        // de l'accueil. Les parcours qui ont un rang passent devant ; les autres gardent l'ordre de chargement
        // (uasort est stable, comme toutes les fonctions de tri depuis PHP 8.0).
        uasort($this->tracks, static fn (Track $a, Track $b) => [null === $a->order, $a->order] <=> [null === $b->order, $b->order]);
        foreach ($this->trackTranslations as $locale => $translations) {
            $this->trackTranslations[$locale] = array_replace(array_intersect_key($this->tracks, $translations), $translations);
        }
        uasort($this->practices, static fn (Practice $a, Practice $b) => [$b->published, $a->exercise->id] <=> [$a->published, $b->exercise->id]);
        // Les traductions, dans l'ordre des exercices français.
        foreach ($this->practiceTranslations as $locale => $translations) {
            $this->practiceTranslations[$locale] = array_replace(array_intersect_key($this->practices, $translations), $translations);
        }
        $this->checkTranslations();
        $this->checkArticlePractices();
        foreach ($this->articles as &$articles) {
            uasort($articles, static fn (Article $a, Article $b) => [$b->published, $a->slug] <=> [$a->published, $b->slug]);
        }
        unset($articles);

        return new LoadedContent($this->packs, $this->tracks, $this->exercises, $this->practices, $this->practiceTranslations, $this->exerciseReader->deprecations(), $this->versionIntros, $this->conceptIntros, $this->conceptThemes, $this->articles, $this->files->watched(), $this->trackTranslations, $this->exerciseTranslations, $this->outdatedTranslations, $this->incompleteTranslations, $this->conceptIntroTranslations);
    }

    private function loadPack(string $directory): void
    {
        $meta = $this->files->parse($directory.'/pack.yaml');
        $id = $this->files->required($meta, 'id', $directory.'/pack.yaml');
        if (isset($this->packs[$id])) {
            throw new ContentException(sprintf('Pack « %s » déclaré deux fois (%s et %s).', $id, $this->packs[$id]->directory, $directory));
        }

        $this->files->watch($directory.'/practice');
        $this->files->watch($directory.'/versions');
        $this->files->watch($directory.'/articles');
        $practiceDirectories = glob($directory.'/practice/*', \GLOB_ONLYDIR) ?: [];
        $pack = new Pack(
            id: $id,
            title: $this->files->required($meta, 'title', $directory.'/pack.yaml'),
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
        foreach (glob($directory.'/notions/*.md') ?: [] as $file) {
            $this->loadConceptIntro($pack, $file, Exercise::LOCALE);
        }
        // Les intros des notions d'une autre langue, nommées comme l'adresse de la notion dans cette langue.
        foreach (Exercise::TRANSLATIONS as $locale) {
            $this->files->watch($directory.'/notions/'.$locale);
            foreach (glob($directory.'/notions/'.$locale.'/*.md') ?: [] as $file) {
                $this->loadConceptIntro($pack, $file, $locale);
            }
        }
        // Le rangement de l'index, en français puis dans chaque autre langue (notions/<langue>/themes.yaml).
        foreach ([Exercise::LOCALE => $directory.'/notions/themes.yaml'] + array_combine(Exercise::TRANSLATIONS, array_map(static fn (string $locale) => $directory.'/notions/'.$locale.'/themes.yaml', Exercise::TRANSLATIONS)) as $locale => $file) {
            $this->files->watch($file);
            if (is_file($file)) {
                $this->loadConceptThemes($pack, $file, $locale);
            }
        }
        foreach (glob($directory.'/articles/*.md') ?: [] as $file) {
            $this->loadArticle($pack, $file, Article::LOCALE);
        }
        foreach (Article::TRANSLATIONS as $locale) {
            $this->files->watch($directory.'/articles/'.$locale);
            foreach (glob($directory.'/articles/'.$locale.'/*.md') ?: [] as $file) {
                $this->loadArticle($pack, $file, $locale);
            }
        }
    }

    private function loadArticle(Pack $pack, string $file, string $locale): void
    {
        $slug = basename($file, '.md');
        if (null !== ($twin = $this->articles[$locale][$slug] ?? null)) {
            throw new ContentException(sprintf('Article « %s » présent deux fois (packs %s et %s).', $slug, $twin->packId, $pack->id));
        }
        $this->articles[$locale][$slug] = $this->articleReader->read($file, $locale, $pack->id);
    }

    private function loadConceptIntro(Pack $pack, string $file, string $locale): void
    {
        $slug = basename($file, '.md');
        $twin = Exercise::LOCALE === $locale ? $this->conceptIntros[$slug] ?? null : $this->conceptIntroTranslations[$locale][$slug] ?? null;
        if (null !== $twin) {
            throw new ContentException(sprintf('Intro de notion « %s » présente deux fois (packs %s et %s).', $slug, $twin['packId'], $pack->id));
        }
        $intro = $this->conceptReader->intro($file, $pack->id);
        if (Exercise::LOCALE === $locale) {
            $this->conceptIntros[$slug] = $intro;
        } else {
            $this->conceptIntroTranslations[$locale][$slug] = $intro;
        }
    }

    /** Un seul pack range les notions d'un framework, dans une langue donnée. */
    private function loadConceptThemes(Pack $pack, string $file, string $locale): void
    {
        foreach ($this->conceptReader->themes($file, $locale) as $framework => $themes) {
            if (isset($this->conceptThemesPacks[$locale][$framework])) {
                throw new ContentException(sprintf('%s : les notions %s sont déjà rangées par le pack « %s ».', $file, $this->environments->frameworks()->labelOf($framework), $this->conceptThemesPacks[$locale][$framework]));
            }
            $this->conceptThemes[$locale][$framework] = $themes;
            $this->conceptThemesPacks[$locale][$framework] = $pack->id;
        }
    }

    /**
     * Une traduction mène à un article français qui existe, et chacun n'a qu'une traduction par langue : c'est ce qui
     * relie les deux pages (hreflang). Vérifié une fois tous les packs lus, l'article traduit pouvant venir d'un autre.
     */
    private function checkTranslations(): void
    {
        foreach (Article::TRANSLATIONS as $locale) {
            $translated = [];
            foreach ($this->articles[$locale] ?? [] as $article) {
                if (!isset($this->articles[Article::LOCALE][$article->translationOf])) {
                    throw new ContentException(sprintf('%s : « translation_of » nomme l\'article « %s », introuvable.', $article->file, $article->translationOf));
                }
                if (null !== ($twin = $translated[$article->translationOf] ?? null)) {
                    throw new ContentException(sprintf('L\'article « %s » a deux traductions (%s et %s).', $article->translationOf, $twin, $article->file));
                }
                $translated[$article->translationOf] = $article->file;
            }
        }
    }

    /** L'exercice qu'un article invite à faire existe : une faute de frappe donnerait une invitation qui ne mène nulle part. */
    private function checkArticlePractices(): void
    {
        foreach ($this->articles as $articles) {
            foreach ($articles as $article) {
                if (null !== $article->practice && !isset($this->practices[$article->practice])) {
                    throw new ContentException(sprintf('%s : « practice » nomme l\'exercice de Pratique « %s », introuvable.', $article->file, $article->practice));
                }
            }
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
        $this->files->watch($file);
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
        $meta = $this->files->parse($file);
        $id = $this->files->required($meta, 'id', $file);
        if (basename($directory) !== $id) {
            throw new ContentException(sprintf('%s : l\'id « %s » doit correspondre au nom du dossier.', $file, $id));
        }
        if (isset($this->tracks[$id])) {
            throw new ContentException(sprintf('Parcours « %s » présent dans deux packs (%s et %s).', $id, $this->tracks[$id]->packId, $pack->id));
        }
        if (ContentRepository::PRACTICE === $id) {
            throw new ContentException(sprintf('%s : « %s » est réservé aux exercices de Pratique, choisissez un autre id de parcours.', $file, $id));
        }

        $environment = $this->files->required($meta, 'environment', $file);
        $this->environments->get($environment); // échoue tôt si l'environnement n'existe pas

        $chapters = [];
        foreach ($meta['chapters'] ?? [] as $chapter) {
            if (isset($chapter['environment'])) {
                $this->environments->get($chapter['environment']);
            }
            $chapterId = $this->files->required($chapter, 'id', $file);
            if (\in_array($chapterId, array_map(static fn (Chapter $c) => $c->id, $chapters), true)) {
                throw new ContentException(sprintf('Parcours « %s » : chapitre « %s » déclaré deux fois.', $id, $chapterId));
            }
            $lesson = $directory.'/chapters/'.$chapterId.'/lesson.md';
            $this->files->watch($lesson);
            $chapters[] = new Chapter(
                id: $chapterId,
                title: $this->files->required($chapter, 'title', $file),
                exerciseIds: $chapter['exercises'] ?? [],
                environment: $chapter['environment'] ?? null,
                lesson: is_file($lesson) ? (string) file_get_contents($lesson) : null,
                description: ContentFields::optionalText($chapter['description'] ?? null, sprintf('%s : chapitre « %s », « description »', $file, $chapterId)),
            );
        }
        if (!$chapters) {
            throw new ContentException(sprintf('%s : au moins un chapitre est requis (clé « chapters »).', $file));
        }

        $visibility = ContentFields::visibility($meta, $file);

        $order = $meta['order'] ?? null;
        if (null !== $order && !\is_int($order)) {
            throw new ContentException(sprintf('%s : « order » doit être un nombre entier (le plus petit s\'affiche en premier).', $file));
        }

        $downloads = $meta['downloads'] ?? [];
        if (!\is_array($downloads) || !array_is_list($downloads) || [] !== array_filter($downloads, static fn ($name) => !\is_string($name) || 1 !== preg_match('/^'.ContentRepository::DOWNLOAD_NAME.'$/', $name))) {
            throw new ContentException(sprintf('%s : « downloads » est une liste de noms de fichiers de DOWNLOADS_DIR (lettres, chiffres, « . », « _ », « - »).', $file));
        }

        $level = $meta['level'] ?? null;
        if (null !== $level && !\in_array($level, Track::LEVELS, true)) {
            throw new ContentException(sprintf('%s : niveau « %s » inconnu (%s).', $file, \is_scalar($level) ? $level : \gettype($level), implode(', ', Track::LEVELS)));
        }

        $image = isset($meta['image']) ? $this->shareImage($meta['image'], $directory, $file) : null;

        $about = $directory.'/about.md';
        $this->files->watch($about);

        $track = new Track($id, $pack->id, $this->files->required($meta, 'title', $file), $meta['description'] ?? '', $environment, $chapters, $directory, $meta['next'] ?? null, $visibility, $order, $downloads, $image, FormerIds::parse($meta['former_ids'] ?? null, $file), $level, about: self::markdown($about));
        $this->tracks[$id] = $track;
        $this->exercises[$id] = [];

        foreach ($track->chapters as $chapter) {
            foreach ($chapter->exerciseIds as $exerciseId) {
                if (ContentRepository::PURCHASE === $exerciseId) {
                    throw new ContentException(sprintf('Parcours « %s » : « %s » est réservé à la page d\'achat du parcours, choisissez un autre id d\'exercice.', $id, $exerciseId));
                }
                if (isset($this->exercises[$id][$exerciseId])) {
                    throw new ContentException(sprintf('Parcours « %s » : exercice « %s » listé deux fois.', $id, $exerciseId));
                }
                $this->exercises[$id][$exerciseId] = $this->loadChapterExercise($track, $chapter, $directory.'/exercises/'.$exerciseId);
            }
        }
        $this->loadTrackTranslations($track, $meta);
    }

    /**
     * Les traductions d'un parcours : celles de ses exercices (`<exercice>/i18n/<langue>/`, comme en Pratique), et
     * `i18n/<langue>/track.yaml` pour ses titres, avec les fiches de cours dans `i18n/<langue>/chapters/<id>/lesson.md`.
     * Un parcours ne paraît dans une langue qu'entièrement traduit (track.yaml, fiches de cours, exercices) : on ne
     * passe pas d'une langue à l'autre au milieu d'un parcours. Ses exercices peuvent se traduire avant lui, un par un,
     * et ce qui manque est signalé par content:check (voir ContentRepository::incompleteTranslations()).
     *
     * @param array<string, mixed> $meta track.yaml du parcours français
     */
    private function loadTrackTranslations(Track $track, array $meta): void
    {
        foreach ($track->chapters as $chapter) {
            foreach ($chapter->exerciseIds as $exerciseId) {
                $exercise = $this->exercises[$track->id][$exerciseId];
                $this->files->watch($exercise->directory.'/i18n');
                foreach ($this->overlays($exercise->directory) as $locale) {
                    $french = $this->files->parse($exercise->directory.'/exercise.yaml');
                    [$translated, $translatedMeta] = $this->exerciseReader->translate($exercise->directory, $french, $locale, $track->id, $track->environmentFor($chapter));
                    $this->exerciseTranslations[$locale][$track->id][$exerciseId] = $translated;
                    if ($this->source($translatedMeta, $exercise->directory.'/i18n/'.$locale.'/exercise.yaml') !== ExerciseReader::fingerprint($exercise->directory)) {
                        $this->outdatedTranslations[$locale][] = $track->id.'/'.$exerciseId;
                    }
                }
            }
        }

        $this->files->watch($track->directory.'/i18n');
        foreach ($this->overlays($track->directory) as $locale) {
            $overlay = $track->directory.'/i18n/'.$locale;
            $file = $overlay.'/track.yaml';
            $this->files->watch($file);
            if (!is_file($file)) {
                throw new ContentException(sprintf('%s : track.yaml manquant (titre et chapitres traduits du parcours).', $overlay));
            }
            $translated = $this->files->parse($file);
            if ($unknown = array_diff(array_keys($translated), ['title', 'description', 'chapters', 'source'])) {
                throw new ContentException(sprintf('%s : « %s » ne se traduit pas : réglez-la dans le parcours français (clés traduisibles : title, description, chapters, source).', $file, implode('», «', $unknown)));
            }
            $texts = $translated['chapters'] ?? [];
            if (!\is_array($texts) || [] !== array_filter($texts, static fn (mixed $chapter) => !\is_array($chapter))) {
                throw new ContentException(sprintf('%s : « chapters » associe à l\'id de chaque chapitre son titre traduit (bonjour: {title: Hello}).', $file));
            }

            // Une traduction incomplète n'empêche pas le reste du contenu de se charger : le français vient de bouger
            // (un exercice, un chapitre ajouté dans l'atelier…), le parcours attend sa traduction pour paraître.
            $missing = array_map(static fn (string $id) => sprintf('exercice « %s » non traduit (exercises/%s/i18n/%s/)', $id, $id, $locale), array_values(array_diff($track->exerciseIds(), array_keys($this->exerciseTranslations[$locale][$track->id] ?? []))));
            $chapters = [];
            foreach ($track->chapters as $chapter) {
                $chapterTexts = $texts[$chapter->id] ?? null;
                $where = sprintf('%s : chapitre « %s »', $file, $chapter->id);
                if (null === $chapterTexts) {
                    $missing[] = sprintf('chapitre « %s » sans titre traduit (chapters.%s.title)', $chapter->id, $chapter->id);
                    continue;
                }
                if ($unknown = array_diff(array_keys($chapterTexts), ['title', 'description'])) {
                    throw new ContentException(sprintf('%s : « %s » ne se traduit pas (title, description).', $where, implode('», «', $unknown)));
                }
                if (null !== $chapter->description && !isset($chapterTexts['description'])) {
                    $missing[] = sprintf('chapitre « %s » sans description traduite', $chapter->id);
                }
                $lesson = $overlay.'/chapters/'.$chapter->id.'/lesson.md';
                $this->files->watch($lesson);
                if ($chapter->hasLesson() && !is_file($lesson)) {
                    $missing[] = sprintf('fiche de cours du chapitre « %s » non traduite (i18n/%s/chapters/%s/lesson.md)', $chapter->id, $locale, $chapter->id);
                }
                $chapters[] = new Chapter(
                    id: $chapter->id,
                    title: $this->files->required($chapterTexts, 'title', $where),
                    exerciseIds: $chapter->exerciseIds,
                    environment: $chapter->environment,
                    lesson: $chapter->hasLesson() && is_file($lesson) ? (string) file_get_contents($lesson) : null,
                    description: ContentFields::optionalText($chapterTexts['description'] ?? null, $where.', « description »'),
                );
            }
            foreach (array_diff(array_keys($texts), array_map(static fn (Chapter $c) => $c->id, $track->chapters)) as $gone) {
                $missing[] = sprintf('chapitre « %s » traduit, mais absent du parcours français', $gone);
            }
            if (!isset($translated['description']) && '' !== ($meta['description'] ?? '')) {
                $missing[] = 'description du parcours non traduite';
            }
            $about = $overlay.'/about.md';
            $this->files->watch($about);
            if (null !== $track->about && !is_file($about)) {
                $missing[] = sprintf('présentation du parcours non traduite (i18n/%s/about.md)', $locale);
            }
            $title = $this->files->required($translated, 'title', $file);
            if ($missing) {
                $this->incompleteTranslations[$locale][$track->id] = $missing;
                continue;
            }
            $this->trackTranslations[$locale][$track->id] = new Track($track->id, $track->packId, $title, (string) ($translated['description'] ?? ''), $track->environment, $chapters, $track->directory, $track->next, $track->visibility, $track->order, $track->downloads, $track->image, $track->formerIds, $track->level, $locale, null === $track->about ? null : self::markdown($about));
            if ($this->source($translated, $file) !== self::trackFingerprint($track)) {
                $this->outdatedTranslations[$locale][] = $track->id;
            }
        }
    }

    /** Le Markdown d'un fichier facultatif : null s'il manque ou ne contient que des blancs. */
    private static function markdown(string $file): ?string
    {
        $text = is_file($file) ? (string) file_get_contents($file) : '';

        return '' === trim($text) ? null : $text;
    }

    /** @return list<string> les langues traduites dans `<dossier>/i18n/` */
    private function overlays(string $directory): array
    {
        $locales = [];
        foreach (glob($directory.'/i18n/*') ?: [] as $overlay) {
            $locale = basename($overlay);
            if (!is_dir($overlay) || !\in_array($locale, Exercise::TRANSLATIONS, true)) {
                throw new ContentException(sprintf('%s : « %s » n\'est pas une langue de traduction (%s).', $directory.'/i18n', $locale, implode(', ', Exercise::TRANSLATIONS)));
            }
            $locales[] = $locale;
        }

        return $locales;
    }

    /** @param array<string, mixed> $meta */
    private function source(array $meta, string $file): ?string
    {
        $source = $meta['source'] ?? null;
        if (null !== $source && !\is_string($source)) {
            throw new ContentException(sprintf('%s : « source » est l\'empreinte du français, un texte : écrivez-la entre guillemets (une empreinte faite de chiffres se lirait comme un nombre).', $file));
        }

        return $source;
    }

    /**
     * L'empreinte d'un parcours français (track.yaml et fiches de cours), que sa traduction recopie dans sa clé
     * « source » : quand elle ne correspond plus, le parcours a changé depuis la traduction.
     */
    public static function trackFingerprint(Track $track): string
    {
        $files = ['track.yaml' => (string) @file_get_contents($track->directory.'/track.yaml')];
        if (null !== $track->about) {
            $files['about.md'] = $track->about;
        }
        foreach ($track->chapters as $chapter) {
            if (null !== $chapter->lesson) {
                $files['chapters/'.$chapter->id.'/lesson.md'] = $chapter->lesson;
            }
        }

        return substr(hash('sha256', serialize($files)), 0, 12);
    }

    private function loadChapterExercise(Track $track, Chapter $chapter, string $directory): Exercise
    {
        $exercise = $this->exerciseReader->read($directory, $track->id, $track->environmentFor($chapter));
        // `base` doit désigner un exercice précédent : pas de cycle possible.
        if (null !== $exercise->base && !isset($this->exercises[$track->id][$exercise->base])) {
            throw new ContentException(sprintf('Exercice « %s » : la base « %s » doit être un exercice précédent du parcours.', $exercise->id, $exercise->base));
        }

        return $exercise;
    }

    private function loadPractice(Pack $pack, string $directory): void
    {
        $file = $directory.'/exercise.yaml';
        $meta = $this->files->parse($file);
        // Un exercice de Pratique se suffit à lui-même : pas de parcours dont hériter, rien à gagner, un compte requis.
        foreach (['access' => 'un compte est toujours demandé', 'base' => 'il ne part d\'aucun autre exercice', 'xp' => 'il ne rapporte pas d\'XP'] as $key => $why) {
            if (\array_key_exists($key, $meta)) {
                throw new ContentException(sprintf('%s : « %s » n\'a pas cours dans un exercice de Pratique (%s).', $file, $key, $why));
            }
        }
        if (!isset($meta['environment'])) {
            throw new ContentException(sprintf('%s : clé « environment » manquante (un exercice de Pratique n\'a pas de parcours dont l\'hériter).', $file));
        }

        $exercise = $this->exerciseReader->read($directory, null, null, $meta);
        if (isset($this->practices[$exercise->id])) {
            throw new ContentException(sprintf('Exercice de Pratique « %s » présent deux fois (packs %s et %s).', $exercise->id, $this->practices[$exercise->id]->packId, $pack->id));
        }

        $published = ContentFields::date($meta['published'] ?? null, $file, 'published');

        $version = $meta['version'] ?? null;
        if (null !== $version && (!\is_string($version) || !preg_match('/^\d+(\.\d+){0,2}$/', $version))) {
            throw new ContentException(sprintf('%s : « version » s\'écrit entre guillemets, par exemple « version: \'8.1\' » (sans guillemets, YAML lit 8.10 comme 8.1).', $file));
        }

        $pullRequest = $meta['pull_request'] ?? null;
        if (null !== $pullRequest && (!\is_string($pullRequest) || !preg_match('#^https://[^\s"\'<>]+$#', $pullRequest))) {
            throw new ContentException(sprintf('%s : « pull_request » doit être une URL https.', $file));
        }

        $visibility = ContentFields::visibility($meta, $file);

        $practice = $this->practices[$exercise->id] = new Practice(
            exercise: $exercise,
            packId: $pack->id,
            framework: $this->environments->get($exercise->environment)->framework->id,
            published: $published,
            summary: $this->files->required($meta, 'summary', $file),
            version: $version,
            pullRequest: $pullRequest,
            visibility: $visibility,
        );
        $this->loadPracticeTranslations($practice, $meta);
    }

    /**
     * Les traductions d'un exercice de Pratique : `i18n/<langue>/` (voir ExerciseReader::translate). Le reste de
     * l'exercice (date, version, visibilité) est celui de l'exercice français.
     *
     * @param array<string, mixed> $meta exercise.yaml de l'exercice français
     */
    private function loadPracticeTranslations(Practice $practice, array $meta): void
    {
        $directory = $practice->exercise->directory;
        $this->files->watch($directory.'/i18n');
        foreach ($this->overlays($directory) as $locale) {
            [$exercise, $translated] = $this->exerciseReader->translate($directory, $meta, $locale);
            $source = $this->source($translated, $directory.'/i18n/'.$locale.'/exercise.yaml');
            $this->practiceTranslations[$locale][$exercise->id] = new Practice(
                exercise: $exercise,
                packId: $practice->packId,
                framework: $practice->framework,
                published: $practice->published,
                summary: $this->files->required($translated, 'summary', $directory.'/i18n/'.$locale.'/exercise.yaml'),
                version: $practice->version,
                pullRequest: $practice->pullRequest,
                visibility: $practice->visibility,
                outdated: $source !== ExerciseReader::fingerprint($directory),
            );
        }
    }

    /** Plus petite image de partage acceptée : celle que LinkedIn, Slack ou X affichent en grand (1,91:1). */
    public const int SHARE_IMAGE_MIN_WIDTH = 1200;
    public const int SHARE_IMAGE_MIN_HEIGHT = 630;

    /**
     * L'image de partage d'un parcours (clé « image ») : un PNG, JPEG ou WebP du dossier du parcours, d'au moins
     * 1200 × 630 et au format paysage des aperçus de liens (entre 1,8:1 et 2:1).
     */
    private function shareImage(mixed $value, string $directory, string $file): string
    {
        if (!\is_string($value) || '' === $value || str_starts_with($value, '/') || \in_array('..', explode('/', $value), true)) {
            throw new ContentException(sprintf('%s : « image » est le chemin d\'un fichier du dossier du parcours (par exemple « partage.png »).', $file));
        }
        $path = $directory.'/'.$value;
        $this->files->watch($path);
        if (!is_file($path)) {
            throw new ContentException(sprintf('%s : image « %s » introuvable.', $file, $value));
        }
        $size = @getimagesize($path);
        if (false === $size || !\in_array($size[2], [\IMAGETYPE_PNG, \IMAGETYPE_JPEG, \IMAGETYPE_WEBP], true)) {
            throw new ContentException(sprintf('%s : image « %s » : PNG, JPEG ou WebP attendu.', $file, $value));
        }
        [$width, $height] = $size;
        $ratio = $height > 0 ? $width / $height : 0;
        if ($width < self::SHARE_IMAGE_MIN_WIDTH || $height < self::SHARE_IMAGE_MIN_HEIGHT || $ratio < 1.8 || $ratio > 2.0) {
            throw new ContentException(sprintf('%s : image « %s » en %d × %d : il faut au moins %d × %d, au format 1,91:1 des aperçus de liens.', $file, $value, $width, $height, self::SHARE_IMAGE_MIN_WIDTH, self::SHARE_IMAGE_MIN_HEIGHT));
        }

        return $path;
    }
}
