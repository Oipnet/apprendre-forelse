<?php

namespace App\Content\Check;

use App\Content\ContentRepository;
use App\Content\Environment;
use App\Content\EnvironmentAssembler;
use App\Content\EnvironmentRegistry;
use App\Content\Framework\FrameworkProfile;
use App\Content\Exercise;
use App\Content\Objective;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Vérifie qu'un exercice est jouable, avec le PHP natif :
 *  1. cohérence du contenu (objectifs ↔ méthodes de test, fichiers éditables présents) ;
 *  2. état de départ + tests → PHPUnit ne doit PAS être entièrement vert ;
 *  3. + solution → PHPUnit doit être entièrement vert, et chaque objectif doit avoir été exécuté.
 *
 * Quand l'apprenant écrit ses propres tests (fichiers éditables sous tests/), ils sont notés
 * comme dans le navigateur (voir playground/src/runtime/php-worker.ts, même logique) :
 *  - « own-tests » : ils passent tous sur l'application correcte (au moins un test) ;
 *  - « mutant:<id> » : ils échouent sur l'application cassée par ce mutant.
 */
final class ExerciseChecker
{
    private readonly Filesystem $filesystem;
    /** @var array<string, string> message d'échec du dernier run, par test */
    private array $failures = [];
    /** @var list<string> caches du projet en cours de vérification (voir Environment::$cacheDirs) */
    private array $cacheDirs = [];
    /** Profil du projet en cours de vérification : il dit comment ses tests se lancent et se nomment. */
    private ?FrameworkProfile $framework = null;

    public function __construct(
        private readonly ContentRepository $content,
        private readonly EnvironmentRegistry $environments,
        /** Dossier de la plateforme : son .env déclare les variables à cacher au projet testé. */
        #[Autowire('%kernel.project_dir%')]
        private readonly string $platformDir = __DIR__.'/../../..',
        /** Reconstitue le projet : la chaîne d'environnements superposée (voir EnvironmentAssembler). */
        private readonly EnvironmentAssembler $assembler = new EnvironmentAssembler(),
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * @param bool $keepWorkdir conserve le projet reconstitué (chemin dans CheckResult::$workdir)
     */
    public function check(Exercise $exercise, bool $keepWorkdir = false): CheckResult
    {
        $result = new CheckResult($exercise);
        $starting = $this->content->startingFiles($exercise);
        $tests = $this->content->testFiles($exercise);
        $solution = $this->content->solutionFiles($exercise);
        $environment = $this->environments->get($exercise->environment);
        $this->cacheDirs = $environment->cacheDirs;
        $this->framework = $environment->framework;

        $this->checkStructure($exercise, $starting, $tests, $solution, $environment, $result);
        $this->checkPracticeVersion($exercise, $environment, $result);
        $this->checkCompletion($starting, $tests, $solution, $environment, $result);
        if (!$result->isOk()) {
            return $result;
        }
        if (!$environment->framework->runsPhpunit()) {
            if (null === $this->nodeBinary()) {
                $result->error('Exercice Nuxt : Node.js est introuvable, impossible de lancer les tests Vitest.');

                return $result;
            }
            if (!is_dir($this->nuxtSimulatorDir().'/node_modules')) {
                $result->error(sprintf('Exercice Nuxt : le simulateur n\'est pas installé (npm ci dans %s).', $this->nuxtSimulatorDir()));

                return $result;
            }
        } elseif (null === $environment->file('vendor/autoload.php')) {
            $result->error(sprintf('Environnement « %s » sans vendor/ : lancez environments/bin/build-env.sh %s.', $environment->id, $environment->id));

            return $result;
        }

        $workdir = sys_get_temp_dir().'/content-check-'.bin2hex(random_bytes(6));
        try {
            $this->assembler->assemble($environment, $workdir);
            $this->write($workdir, [...$starting, ...$tests]);

            $before = $this->grade($exercise, $workdir);
            if (null === $before) {
                $result->error('État de départ : PHPUnit n\'a produit aucun rapport. '.($this->failures['*'] ?? '(erreur fatale ?)'));
            } elseif (!\in_array(false, $before, true)) {
                $result->error('État de départ : tous les tests passent déjà, l\'exercice n\'a rien à faire.');
            }

            $this->write($workdir, $solution);
            $this->clearCaches($workdir);
            $after = $this->grade($exercise, $workdir);
            if (null === $after) {
                $result->error('Solution : PHPUnit n\'a produit aucun rapport (erreur fatale ?).');

                return $result;
            }
            foreach ($exercise->objectives as $objective) {
                if (!\array_key_exists($objective->test, $after)) {
                    $result->error(sprintf('Solution : l\'objectif « %s » n\'a pas été exécuté.', $objective->test));
                }
            }
            foreach ($after as $test => $passed) {
                if (!$passed) {
                    $result->error(match (true) {
                        Objective::OWN_TESTS === $test => 'Solution : ses propres tests ne passent pas — '.($this->failures[$test] ?? '?'),
                        str_starts_with($test, Objective::MUTANT_PREFIX) => sprintf('Solution : le mutant « %s » n\'est détecté par aucun test — %s', substr($test, \strlen(Objective::MUTANT_PREFIX)), $this->failures[$test] ?? '?'),
                        default => sprintf('Solution : le test %s échoue — %s', $test, $this->failures[$test] ?? '?'),
                    });
                }
            }
            $result->objectivesBefore = array_map(static fn ($o) => $before[$o->test] ?? false, $exercise->objectives);
        } finally {
            if ($keepWorkdir) {
                $result->workdir = $workdir;
            } else {
                $this->filesystem->remove($workdir);
            }
        }

        return $result;
    }

    /**
     * Un exercice de Pratique qui annonce une nouveauté (`version: '8.1'`) doit tourner sur un framework qui l'a :
     * sinon l'apprenant chercherait une fonctionnalité absente de son projet.
     */
    private function checkPracticeVersion(Exercise $exercise, Environment $environment, CheckResult $result): void
    {
        $version = null === $exercise->trackId ? $this->content->findPractice($exercise->id)?->version : null;
        if (null === $version) {
            return;
        }
        // Le paquet dont la version fait foi est déclaré par le profil ; certains n'en ont pas (Docker, Nuxt).
        $package = $environment->framework->versionPackage;
        if (null === $package) {
            $result->error(sprintf('« version » n\'a pas de sens pour l\'environnement « %s » (%s) : retirez-la.', $environment->id, $environment->framework->label));

            return;
        }
        $lock = json_decode((string) @file_get_contents((string) $environment->file('composer.lock')), true);
        $installed = null;
        foreach ($lock['packages'] ?? [] as $candidate) {
            if (($candidate['name'] ?? null) === $package) {
                $installed = (string) $candidate['version'];
            }
        }
        if (null === $installed) {
            $result->error(sprintf('« version: %s » : %s introuvable dans %s/composer.lock.', $version, $package, $environment->id));

            return;
        }

        $parser = new VersionParser();
        if (Comparator::lessThan($parser->normalize($installed), $parser->normalize($version))) {
            $result->error(sprintf('La fonctionnalité arrive en %s, or l\'environnement « %s » a %s %s : mettez l\'environnement à jour.', $version, $environment->id, $package, $installed));
        }
    }

    /**
     * @param array<string, string> $starting
     * @param array<string, string> $tests
     * @param array<string, string> $solution
     */
    private function checkStructure(Exercise $exercise, array $starting, array $tests, array $solution, Environment $environment, CheckResult $result): void
    {
        if (!$tests && !$exercise->ownTests()) {
            $result->error('Aucun test dans tests/.');
        }
        if (!$solution) {
            $result->error('Aucune solution dans solution/.');
        }
        $testCode = implode("\n", $tests);
        foreach (array_filter($exercise->objectives, static fn (Objective $o) => $o->isHiddenTest()) as $objective) {
            // PHPUnit : une méthode du nom de l'objectif ; Vitest : un it() ou test() de ce titre.
            $phpunit = $this->framework?->runsPhpunit() ?? true;
            $pattern = $phpunit
                ? '/function\s+'.preg_quote($objective->test, '/').'\s*\(/'
                : '/\b(?:it|test)(?:\.\w+)*\s*\(\s*([\'"`])'.preg_quote($objective->test, '/').'\1/u';
            if (!preg_match($pattern, $testCode)) {
                $result->error(sprintf('Objectif « %s » : aucun %s de ce nom dans tests/.', $objective->test, $phpunit ? 'méthode de test' : 'test it()'));
            }
        }
        // Un motif (migrations/*.php) désigne des fichiers qui n'existent pas encore : rien à vérifier.
        foreach ([...$exercise->editablePaths(), ...$exercise->readonly] as $path) {
            if (!isset($starting[$path]) && null === $environment->file($path)) {
                $result->error(sprintf('Fichier « %s » introuvable (ni dans starter/, ni dans une base, ni dans l\'environnement).', $path));
            }
        }
        // Le fichier ouvert au démarrage, quand seul un motif le couvre, doit faire partie de l'état de départ.
        if (!\in_array($exercise->open, [...$exercise->editablePaths(), ...$exercise->readonly], true) && !isset($starting[$exercise->open])) {
            $result->error(sprintf('« open » (%s) : ce fichier n\'existe pas au départ (ni dans starter/, ni dans une base).', $exercise->open));
        }
        foreach ($exercise->mutants as $mutant) {
            foreach ($mutant->changes as $change) {
                $depuisLEnvironnement = $environment->file($change['file']);
                $code = $solution[$change['file']] ?? $starting[$change['file']] ?? (null === $depuisLEnvironnement ? null : (string) file_get_contents($depuisLEnvironnement));
                if (null === $code || !str_contains($code, $change['search'])) {
                    $result->error(sprintf('Mutant « %s » : texte à remplacer introuvable dans %s (%s).', $mutant->id, $change['file'], $change['search']));
                }
            }
        }
        foreach (array_keys($solution) as $path) {
            if (!$exercise->isEditable($path)) {
                $result->warning(sprintf('La solution modifie « %s », qui n\'est pas éditable par l\'apprenant.', $path));
            }
        }
    }

    /**
     * Les classes que l'apprenant doit importer lui-même — citées par la solution, absentes de l'état de départ —
     * doivent figurer dans l'index de complétion de l'environnement. Sinon l'éditeur ne les propose pas, alors que
     * l'exercice demande précisément de les écrire. La liste des namespaces indexés est dans
     * environments/bin/build-completion.php ; l'index lui-même est produit par build-env.sh.
     *
     * @param array<string, string> $starting
     * @param array<string, string> $tests
     * @param array<string, string> $solution
     */
    private function checkCompletion(array $starting, array $tests, array $solution, Environment $environment, CheckResult $result): void
    {
        $indexPath = $this->platformDir.'/public/'.$environment->completionIndexPath();
        if (!is_file($indexPath)) {
            $result->warning(sprintf('Index de complétion absent (%s) : les imports de la solution n\'ont pas été vérifiés. Lancez environments/bin/build-env.sh %s.', $environment->completionIndexPath(), $environment->id));

            return;
        }
        $known = json_decode((string) file_get_contents($indexPath), true)['classes'] ?? [];
        // Environnement sans PHP (Nuxt) : son index est vide, il n'y a pas d'import à vérifier.
        if (!$known) {
            return;
        }

        // Une classe que l'exercice fournit lui-même (entité, factory, test, y compris héritée d'une base)
        // n'a rien à faire dans l'index : elle n'existe que le temps de l'exercice.
        $provided = [];
        foreach ([...$starting, ...$tests, ...$solution] as $path => $code) {
            foreach ($this->declaredClasses($path, $code) as $class) {
                $provided[$class] = true;
            }
        }
        // Ce que l'état de départ importe déjà est sous les yeux de l'apprenant : il n'a pas à le retrouver.
        $already = [];
        foreach ($starting as $path => $code) {
            foreach ($this->importedClasses($path, $code) as $class) {
                $already[$class] = true;
            }
        }

        $missing = [];
        foreach ($solution as $path => $code) {
            foreach ($this->importedClasses($path, $code) as $class) {
                if (!isset($already[$class]) && !isset($provided[$class]) && !isset($known[$class])) {
                    $missing[$class] = true;
                }
            }
        }
        if ($missing) {
            $result->error(sprintf(
                'Complétion : %s hors de l\'index de « %s ». L\'apprenant doit écrire ces imports, l\'éditeur ne les lui proposera pas : ajoutez leur namespace à environments/bin/build-completion.php.',
                implode(', ', array_keys($missing)),
                $environment->id,
            ));
        }
    }

    /**
     * Imports d'un fichier PHP, en pleine qualification. Le `use` d'un trait est indenté dans le corps de la
     * classe, celui d'un import commence la ligne : la distinction tient à cette colonne.
     *
     * @return list<string>
     */
    private function importedClasses(string $path, string $code): array
    {
        if (!str_ends_with($path, '.php')) {
            return [];
        }
        preg_match_all('/^use\s+(?!function\s|const\s)([A-Z][\w\\\\]*\\\\[\w\\\\]+?)(?:\s+as\s+\w+)?\s*;/m', $code, $matches);

        return $matches[1];
    }

    /**
     * Classes, interfaces, traits et énumérations qu'un fichier PHP déclare, en pleine qualification.
     *
     * @return list<string>
     */
    private function declaredClasses(string $path, string $code): array
    {
        if (!str_ends_with($path, '.php')) {
            return [];
        }
        $namespace = preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $code, $found) ? $found[1].'\\' : '';
        preg_match_all('/^(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $code, $matches);

        return array_map(static fn (string $name) => $namespace.$name, $matches[1]);
    }

    /**
     * Vide les caches du projet (container Symfony, vues Blade compilées…) : le run suivant doit
     * repartir du code courant, y compris quand un fichier a été réécrit dans la même seconde.
     */
    private function clearCaches(string $workdir): void
    {
        foreach ($this->cacheDirs as $dir) {
            $this->filesystem->remove($workdir.'/'.$dir);
            // Le dossier lui-même doit rester : Laravel refuse de démarrer sans son dossier de vues compilées.
            $this->filesystem->mkdir($workdir.'/'.$dir);
        }
    }

    /** @param array<string, string> $files */
    private function write(string $workdir, array $files): void
    {
        foreach ($files as $path => $content) {
            $this->filesystem->dumpFile($workdir.'/'.$path, $content);
        }
    }

    /**
     * Résultats par test (méthodes des tests cachés et de l'apprenant), plus les objectifs
     * « own-tests » et « mutant:<id> » quand l'exercice note les tests de l'apprenant.
     *
     * @return array<string, bool>|null null si PHPUnit n'a rien rapporté
     */
    private function grade(Exercise $exercise, string $workdir): ?array
    {
        $cases = $this->runTests($workdir);
        if (null === $cases) {
            return null;
        }
        $results = array_map(static fn (array $case) => 'passed' === $case['status'], $cases);
        $ownTests = $exercise->ownTests();
        if (!$ownTests) {
            return $results;
        }

        $own = array_filter($cases, static fn (array $case) => self::isOwnTest($case['file'], $ownTests));
        $ownPassing = $own && !array_filter($own, static fn (array $case) => 'passed' !== $case['status']);
        $results[Objective::OWN_TESTS] = $ownPassing;
        if (!$ownPassing) {
            $this->failures[Objective::OWN_TESTS] = $own ? 'des tests de l\'apprenant échouent sur l\'application correcte.' : 'aucun test de l\'apprenant exécuté.';
        }

        foreach ($exercise->mutants as $mutant) {
            $key = Objective::MUTANT_PREFIX.$mutant->id;
            if (!$ownPassing) {
                $results[$key] = false;
                continue;
            }
            $originals = [];
            foreach ($mutant->changes as $change) {
                $path = $workdir.'/'.$change['file'];
                $originals[$path] ??= (string) file_get_contents($path);
                $this->filesystem->dumpFile($path, str_replace($change['search'], $change['replace'], (string) file_get_contents($path)));
            }
            $this->clearCaches($workdir);
            $mutantCases = $this->runTests($workdir, $ownTests);
            foreach ($originals as $path => $content) {
                $this->filesystem->dumpFile($path, $content);
            }
            $this->clearCaches($workdir);

            // Détecté si un test de l'apprenant échoue (ou si PHPUnit s'arrête : l'application est cassée).
            $results[$key] = null === $mutantCases || [] !== array_filter($mutantCases, static fn (array $case) => 'passed' !== $case['status']);
            if (!$results[$key]) {
                $this->failures[$key] = sprintf('les tests passent encore quand %s.', $mutant->label);
            }
        }

        return $results;
    }

    /** @param list<string> $ownTests */
    private static function isOwnTest(string $file, array $ownTests): bool
    {
        foreach ($ownTests as $path) {
            if (str_ends_with($file, '/'.$path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $paths fichiers de test à lancer (tous par défaut)
     *
     * @return array<string, array{status: string, file: string}>|null par nom de test, null si PHPUnit n'a rien rapporté
     */
    private function runTests(string $workdir, array $paths = []): ?array
    {
        if (!($this->framework?->runsPhpunit() ?? true)) {
            return $this->runVitest($workdir, $paths);
        }
        $junit = $workdir.'/var/junit.xml';
        $this->filesystem->remove($junit);
        // Xdebug désactivé : inutile ici, plus lent, et il a déjà fait planter PHP sur des pages d'erreur.
        // 512 Mo : un exercice qui manipule beaucoup de fichiers (parcours Docker) dépasse les 128 Mo par défaut.
        $process = new Process([\PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'memory_limit=512M', 'vendor/bin/phpunit', '--colors=never', '--do-not-cache-result', '--log-junit', $junit, ...$paths], $workdir, $this->isolatedEnv(), timeout: 120);
        try {
            $process->run();
        } catch (ProcessSignaledException $e) {
            // Crash de PHP (ex. récursion infinie) : signalé comme une absence de rapport.
            $this->failures = ['*' => sprintf('PHP a planté (signal %d).', $e->getSignal())];

            return null;
        }
        if (!is_file($junit)) {
            return null;
        }

        $results = [];
        if (!$paths) {
            $this->failures = [];
        }
        foreach (simplexml_load_file($junit)->xpath('//testcase') as $testcase) {
            // SimpleXML renvoie un élément vide (jamais null) pour une balise absente : isset() obligatoire.
            $failure = isset($testcase->failure) ? $testcase->failure : (isset($testcase->error) ? $testcase->error : null);
            $results[(string) $testcase['name']] = [
                // Un test ignoré ou incomplet (markTestIncomplete) ne compte pas comme réussi.
                'status' => null !== $failure ? 'failed' : (isset($testcase->skipped) ? 'skipped' : 'passed'),
                'file' => (string) $testcase['file'],
            ];
            if (null !== $failure && !$paths) {
                $lines = array_filter(explode("\n", (string) $failure), static fn ($l) => '' !== trim($l) && !str_starts_with($l, '/'));
                $this->failures[(string) $testcase['name']] = implode(' ', \array_slice($lines, 1, 2));
            }
        }

        return $results;
    }

    /**
     * Tests Vitest d'un projet Nuxt, lancés par le simulateur (tools/nuxt-sim/bin/tests.ts) : le même runner
     * que dans le navigateur, donc le même verdict.
     *
     * @param list<string> $paths fichiers de test à lancer (tous par défaut)
     *
     * @return array<string, array{status: string, file: string}>|null par titre de test, null si aucun test n'a tourné
     */
    private function runVitest(string $workdir, array $paths): ?array
    {
        $process = new Process([(string) $this->nodeBinary(), '--experimental-transform-types', '--no-warnings', $this->nuxtSimulatorDir().'/bin/tests.ts', $workdir, ...$paths], $workdir, ['NODE_ENV' => false], timeout: 120);
        $process->run();
        $report = json_decode($process->getOutput(), true);
        if (!\is_array($report) || !\is_array($report['cases'] ?? null)) {
            $this->failures = ['*' => trim($process->getErrorOutput()) ?: 'le simulateur Nuxt n\'a rien rapporté.'];

            return null;
        }
        if (!$paths) {
            $this->failures = [];
        }
        $results = [];
        foreach ($report['cases'] as $case) {
            $results[(string) $case['name']] = ['status' => 'error' === $case['status'] ? 'failed' : (string) $case['status'], 'file' => (string) ($case['file'] ?? '')];
            if (!$paths && \in_array($case['status'], ['failed', 'error'], true)) {
                $this->failures[(string) $case['name']] = (string) ($case['message'] ?? '');
            }
        }
        if (!$results) {
            $this->failures['*'] = (string) ($report['output'] ?? '');

            return null;
        }

        return $results;
    }

    private function nuxtSimulatorDir(): string
    {
        return $this->platformDir.'/../tools/nuxt-sim';
    }

    private function nodeBinary(): ?string
    {
        return (new ExecutableFinder())->find('node');
    }

    /**
     * Le projet testé doit charger ses propres .env : on lui cache les variables de la plateforme
     * (Symfony Process transmet l'environnement sinon). Deux sources, parce qu'elles diffèrent :
     * en développement, le Dotenv de la plateforme les a injectées (SYMFONY_DOTENV_VARS) ; en
     * production (Docker), elles sont de vraies variables d'environnement, absentes de cette liste.
     * D'où la lecture des noms déclarés dans le .env de la plateforme. Sans cela, DATABASE_URL
     * de la production atteint les tests d'un exercice Doctrine, qui cherchent alors « formation_test ».
     *
     * @return array<string, string|false>
     */
    public function isolatedEnv(): array
    {
        $env = ['APP_ENV' => 'test', 'SYMFONY_DOTENV_VARS' => false, 'SYMFONY_DOTENV_PATH' => false];

        $declared = is_file($this->platformDir.'/.env') ? array_keys((new Dotenv())->parse((string) file_get_contents($this->platformDir.'/.env'))) : [];
        $injected = explode(',', (string) ($_SERVER['SYMFONY_DOTENV_VARS'] ?? ''));
        foreach ([...$declared, ...$injected, 'DATABASE_URL'] as $name) {
            if ('' !== $name && 'APP_ENV' !== $name) {
                $env[$name] = false;
            }
        }

        return $env;
    }
}
