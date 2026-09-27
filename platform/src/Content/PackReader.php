<?php

namespace App\Content;

use App\Version;
use Composer\Semver\Semver;

/**
 * Un passage de lecture des packs (CONTENT_PACKS_PATHS) : packs, parcours, exercices de Pratique et intros de
 * versions, validés au fil de l'eau. Chaque lecture part d'une instance neuve (voir PackLoader).
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
    /** @var array<string, array{markdown: string, file: string, packId: string}> */
    private array $versionIntros = [];
    private readonly PackFiles $files;
    private readonly ExerciseReader $exerciseReader;

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

        // L'ordre de chargement dépend des chemins configurés et du nom des dossiers : il ne doit pas décider
        // de l'accueil. Les parcours qui ont un rang passent devant ; les autres gardent l'ordre de chargement
        // (uasort est stable, comme toutes les fonctions de tri depuis PHP 8.0).
        uasort($this->tracks, static fn (Track $a, Track $b) => [null === $a->order, $a->order] <=> [null === $b->order, $b->order]);
        uasort($this->practices, static fn (Practice $a, Practice $b) => [$b->published, $a->exercise->id] <=> [$a->published, $b->exercise->id]);

        return new LoadedContent($this->packs, $this->tracks, $this->exercises, $this->practices, $this->exerciseReader->deprecations(), $this->versionIntros, $this->files->watched());
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
        if (!\is_array($downloads) || !array_is_list($downloads) || [] !== array_filter($downloads, static fn ($name) => !\is_string($name) || 1 !== preg_match('/^'.ContentRepository::DOWNLOAD_NAME.'$/', $name))) {
            throw new ContentException(sprintf('%s : « downloads » est une liste de noms de fichiers de DOWNLOADS_DIR (lettres, chiffres, « . », « _ », « - »).', $file));
        }

        $track = new Track($id, $pack->id, $this->files->required($meta, 'title', $file), $meta['description'] ?? '', $environment, $chapters, $directory, $meta['next'] ?? null, $visibility, $order, $downloads);
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
    }

    private function loadChapterExercise(Track $track, Chapter $chapter, string $directory): Exercise
    {
        $exercise = $this->exerciseReader->read($directory, $track->id, $chapter->environment ?? $track->environment);
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
            summary: $this->files->required($meta, 'summary', $file),
            version: $version,
            pullRequest: $pullRequest,
            visibility: $visibility,
        );
    }
}
