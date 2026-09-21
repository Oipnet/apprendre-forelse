<?php

namespace App\Content\Framework;

/**
 * Ce que le moteur sait d'un framework : de quoi le faire tourner, le vérifier, le décrire et l'écrire.
 *
 * C'était, jusqu'ici, la même connaissance recopiée dans une dizaine d'endroits — un `match` dans le
 * vérificateur, un autre dans les rédacteurs, une table de libellés dans un contrôleur, trois tables de
 * plus dans le playground. Un profil la déclare **une fois** ; le navigateur la reçoit dans la charge
 * utile de l'exercice au lieu de la réécrire.
 *
 * C'est aussi la moitié serveur du contrat qu'un paquet de plateforme devra remplir : ajouter un
 * framework, c'est fournir un FrameworkProfileProvider (plus, s'il s'agit d'une nouvelle famille, un
 * worker côté navigateur — celui-là reste du code).
 */
final readonly class FrameworkProfile
{
    /** Lanceurs de tests connus du moteur : PHPUnit en PHP natif, Vitest sous Node. */
    public const string PHPUNIT = 'phpunit';
    public const string VITEST = 'vitest';

    /** Runtimes du navigateur : PHP compilé en WebAssembly, et le simulateur Nuxt. */
    public const string PHP_WASM = 'php-wasm';
    public const string NUXT_SIM = 'nuxt-sim';

    /**
     * @param string                $id              identifiant écrit dans environment.yaml (« symfony »)
     * @param string                $label           nom affiché (« Symfony »)
     * @param int                   $order           rang dans les listes (le plus petit d'abord), comme « order: » d'un parcours
     * @param string                $console         la console du projet, telle qu'on la tape (« bin/console »)
     * @param string                $consoleExample  commande d'exemple, en filigrane du champ de la console
     * @param string                $bootNote        ce qu'on explique à l'apprenant pendant le démarrage
     * @param string                $unpackLabel     étape de démarrage : « Décompression du projet Symfony »
     * @param string                $testRunner      PHPUNIT ou VITEST : comment content:check et le navigateur notent
     * @param string|null           $versionPackage  paquet dont la version fait foi pour « version: » (null : pas de versions)
     * @param list<string>          $projectDirs     dossiers du projet, visibles dans l'explorateur
     * @param list<string>          $codeDirs        ce qui est « du code » pour la rédaction assistée (hors tests et assets)
     * @param list<string>          $cacheDirs       caches à vider entre deux runs, côté serveur (content:check)
     * @param list<string>          $testCaches      caches à vider entre deux runs, côté navigateur
     * @param list<string>          $hidden          dossiers masqués dans l'explorateur
     * @param array<string, string> $namespaceRoots  premier dossier => racine de namespace (« src » => « App »)
     * @param string                $lessonLanguages langages des blocs de code d'une fiche de cours
     * @param string|null           $drafting        conventions du projet, données au modèle qui rédige (null : il ne sait pas)
     * @param string                $runtime         qui l'exécute dans le navigateur (PHP_WASM, NUXT_SIM, ou un runtime ajouté)
     * @param list<string>          $snippets        familles d'extraits proposés par l'éditeur (« php », « laravel », « docker »)
     * @param array<string, string> $consoleAliases  préfixes tolérés dans la console => ce qui les remplace (« » : retiré)
     */
    public function __construct(
        public string $id,
        public string $label,
        public int $order,
        public string $console,
        public string $consoleExample,
        public string $bootNote,
        public string $unpackLabel,
        public string $testRunner,
        public ?string $versionPackage,
        public array $projectDirs,
        public array $codeDirs,
        public array $cacheDirs,
        public array $testCaches,
        public array $hidden,
        public array $namespaceRoots,
        public string $lessonLanguages,
        public ?string $drafting,
        public string $runtime = self::PHP_WASM,
        public array $snippets = [],
        public array $consoleAliases = [],
        /**
         * Le module Node qui lance les tests de ce framework pour `content:check`, quand ce ne sont pas
         * des tests PHPUnit.
         *
         * Un **spécificateur de paquet**, jamais un chemin : le moteur le résout depuis le playground
         * (`node --input-type=module -e "import.meta.resolve(…)"`). Un runtime livré par un paquet
         * déclare ainsi son lanceur sans que le moteur sache où ce paquet est installé — ni qu'il
         * existe. Le module reçoit le dossier du projet puis les fichiers de test, et écrit sur la
         * sortie standard un JSON `{cases: [{name, status, file, message}], output}`.
         */
        public ?string $testModule = null,
    ) {
    }

    /** Les tests de ce framework sont-ils des tests PHPUnit ? (Sinon : Vitest, sous Node.) */
    public function runsPhpunit(): bool
    {
        return self::PHPUNIT === $this->testRunner;
    }

    /**
     * Ce que le navigateur a besoin de savoir du framework (voir playground/src/app/types.ts).
     *
     * @return array<string, mixed>
     */
    public function forBrowser(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'console' => $this->console,
            'consoleExample' => $this->consoleExample,
            'bootNote' => $this->bootNote,
            'unpackLabel' => $this->unpackLabel,
            'testRunner' => $this->testRunner,
            'runtime' => $this->runtime,
            'snippets' => $this->snippets,
            'consoleAliases' => (object) $this->consoleAliases,
            'projectDirs' => $this->projectDirs,
            'testCaches' => $this->testCaches,
            'hidden' => $this->hidden,
            'namespaceRoots' => (object) $this->namespaceRoots,
        ];
    }
}
