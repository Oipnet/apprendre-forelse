<?php

namespace App\Content;

/**
 * Lit et valide un exercice (exercise.yaml, instructions.md), d'un parcours ou de la Pratique. Les erreurs de format
 * sont des ContentException ; ce qui demande d'exécuter le contenu est laissé à content:check.
 */
final class ExerciseReader
{
    /** @var array<string, string> clés dépréciées rencontrées, par « parcours/exercice » (voir ContentRepository::deprecations()) */
    private array $deprecations = [];

    public function __construct(
        private readonly PackFiles $files,
        private readonly EnvironmentRegistry $environments,
    ) {
    }

    /** @return array<string, string> */
    public function deprecations(): array
    {
        return $this->deprecations;
    }

    /**
     * @param string|null               $trackId              null pour un exercice de Pratique
     * @param string|null               $inheritedEnvironment environnement du chapitre ou du parcours, faute de clé « environment »
     * @param array<string, mixed>|null $meta                 exercise.yaml déjà lu
     */
    public function read(string $directory, ?string $trackId, ?string $inheritedEnvironment, ?array $meta = null): Exercise
    {
        $file = $directory.'/exercise.yaml';
        $meta ??= $this->files->parse($file);
        $id = $this->files->required($meta, 'id', $file);
        if (null !== $trackId && \array_key_exists('access', $meta)) {
            // Depuis la 0.8.0, le moteur ouvre tout le premier chapitre d'un parcours à tout compte : la clé n'a plus d'effet.
            $this->deprecations[$trackId.'/'.$id] = '« access » est dépréciée et sans effet : le premier chapitre de chaque parcours est gratuit pour tout compte, et la suite dépend de l\'accès au parcours. Retirez la clé ; elle sera refusée dans une version majeure.';
        }
        if (basename($directory) !== $id) {
            throw new ContentException(sprintf('%s : l\'id « %s » doit correspondre au nom du dossier.', $file, $id));
        }
        $this->files->watch($directory.'/instructions.md');
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
            $label = $this->files->required($o, 'label', $file);
            if (isset($o['mutant'])) {
                return isset($mutants[$o['mutant']])
                    ? new Objective(Objective::MUTANT_PREFIX.$o['mutant'], $label)
                    : throw new ContentException(sprintf('%s : l\'objectif cite le mutant « %s », qui n\'est pas déclaré dans « mutants ».', $file, $o['mutant']));
            }

            return new Objective(isset($o['own-tests']) ? Objective::OWN_TESTS : $this->files->required($o, 'test', $file), $label);
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
            title: $this->files->required($meta, 'title', $file),
            // YAML lit « 404 » comme un entier : les étiquettes restent des chaînes.
            concepts: array_map('strval', $meta['concepts'] ?? []),
            xp: (int) ($meta['xp'] ?? 0),
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
            formerIds: FormerIds::parse($meta['former_ids'] ?? null, $file),
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
                'file' => $this->files->required($change, 'file', $file),
                'search' => $this->files->required($change, 'search', $file),
                'replace' => (string) ($change['replace'] ?? ''),
            ];
        }

        return new Mutant($this->files->required($mutant, 'id', $file), $this->files->required($mutant, 'label', $file), $changes);
    }

    private function docLink(mixed $doc, string $file): DocLink
    {
        if (!\is_array($doc)) {
            throw new ContentException(sprintf('%s : chaque entrée de « docs » est un objet {title, url}.', $file));
        }
        $url = $this->files->required($doc, 'url', $file);
        // Affiché comme lien dans la plateforme : pas de javascript:, data:…
        if (!preg_match('#^https?://[^\s"\'<>]+$#', $url)) {
            throw new ContentException(sprintf('%s : le lien de documentation « %s » doit être une URL http(s).', $file, $url));
        }

        return new DocLink($this->files->required($doc, 'title', $file), $url);
    }

    private function exampleRequest(mixed $request, string $file): ExampleRequest
    {
        if (!\is_array($request)) {
            throw new ContentException(sprintf('%s : chaque entrée de « requests » est un objet (method, path…).', $file));
        }
        $method = strtoupper((string) ($request['method'] ?? 'GET'));
        $path = (string) $this->files->required($request, 'path', $file);
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
}
