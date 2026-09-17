<?php

namespace App\Content;

final readonly class Exercise
{
    /**
     * @param list<string>    $concepts
     * @param list<string>    $editable   fichiers modifiables par l'apprenant ; un motif (`migrations/*.php`)
     *                                    couvre aussi les fichiers qu'une commande console va créer
     * @param list<string>    $readonly   fichiers visibles mais verrouillés
     * @param list<Objective> $objectives
     * @param list<string>    $hints      indices gradués (markdown)
     * @param list<string>    $setup      commandes console lancées au chargement de l'aperçu
     * @param list<ExampleRequest> $requests requêtes d'exemple de l'onglet « Requêtes »
     * @param list<DocLink>        $docs     documentation utile pour résoudre l'exercice
     * @param list<Mutant>         $mutants  versions cassées de l'application, que les tests de l'apprenant doivent détecter
     */
    public function __construct(
        public string $id,
        /** Le parcours de l'exercice, ou null pour un exercice de Pratique (voir Practice). */
        public ?string $trackId,
        public string $title,
        public array $concepts,
        public int $xp,
        public Access $access,
        public string $environment,
        public ?string $base,
        public string $open,
        public string $preview,
        public array $editable,
        public array $readonly,
        public array $objectives,
        public array $hints,
        public string $instructions,
        public string $directory,
        public array $setup = [],
        public array $requests = [],
        public array $docs = [],
        public array $mutants = [],
        /** Durée estimée, en minutes, pour le public du parcours (clé `duration`, facultative). */
        public ?int $duration = null,
    ) {
    }

    /** Le dernier exercice d'un chapitre, reconnaissable à son titre : le pack ne le déclare pas autrement. */
    public function isBoss(): bool
    {
        return 1 === preg_match('/\bboss\b/iu', $this->title);
    }

    /**
     * Les tests écrits par l'apprenant : ses fichiers éditables sous tests/.
     *
     * @return list<string>
     */
    public function ownTests(): array
    {
        // Classe PHPUnit (…Test.php) ou fichier Vitest (….test.ts, ….spec.ts) d'un projet Nuxt.
        return array_values(array_filter($this->editablePaths(), static fn (string $path) => str_starts_with($path, 'tests/') && (str_ends_with($path, 'Test.php') || 1 === preg_match('/\.(test|spec)\.[cm]?[jt]sx?$/', $path))));
    }

    /**
     * Les fichiers modifiables nommés explicitement (sans motif).
     *
     * @return list<string>
     */
    public function editablePaths(): array
    {
        return array_values(array_filter($this->editable, static fn (string $entry) => !str_contains($entry, '*')));
    }

    /**
     * L'apprenant peut-il modifier ce fichier ? `*` ne franchit pas un « / » : `migrations/*.php`
     * couvre `migrations/Version1.php`, pas `migrations/archives/Version1.php`.
     */
    public function isEditable(string $path): bool
    {
        foreach ($this->editable as $entry) {
            if ($entry === $path || (str_contains($entry, '*') && fnmatch($entry, $path, \FNM_PATHNAME))) {
                return true;
            }
        }

        return false;
    }
}
