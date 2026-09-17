<?php

namespace App\Content\Check;

use App\Content\ContentRepository;
use App\Content\Environment;
use App\Content\EnvironmentRegistry;
use App\Content\Exercise;
use App\Content\Objective;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
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
    /** Framework du projet en cours de vérification : les tests d'un projet Nuxt sont des tests Vitest. */
    private string $framework = 'symfony';

    public function __construct(
        private readonly ContentRepository $content,
        private readonly EnvironmentRegistry $environments,
        /** Dossier de la plateforme : son .env déclare les variables à cacher au projet testé. */
        #[Autowire('%kernel.project_dir%')]
        private readonly string $platformDir = __DIR__.'/../../..',
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

        $this->checkStructure($exercise, $starting, $tests, $solution, $environment->directory, $result);
        $this->checkPracticeVersion($exercise, $environment, $result);
        if (!$result->isOk()) {
            return $result;
        }
        if ('nuxt' === $environment->framework) {
            if (null === $this->nodeBinary()) {
                $result->error('Exercice Nuxt : Node.js est introuvable, impossible de lancer les tests Vitest.');

                return $result;
            }
            if (!is_dir($this->nuxtSimulatorDir().'/node_modules')) {
                $result->error(sprintf('Exercice Nuxt : le simulateur n\'est pas installé (npm ci dans %s).', $this->nuxtSimulatorDir()));

                return $result;
            }
        } elseif (!is_file($environment->directory.'/vendor/autoload.php')) {
            $result->error(sprintf('Environnement « %s » sans vendor/ : lancez tools/build-env.sh %s.', $environment->id, $environment->id));

            return $result;
        }

        $workdir = sys_get_temp_dir().'/content-check-'.bin2hex(random_bytes(6));
        try {
            $this->filesystem->mirror($environment->directory, $workdir, (new Finder())->in($environment->directory)->ignoreDotFiles(false)->exclude(['var', '.phpunit.cache', 'node_modules', '.nuxt', '.output']));
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

    /** Paquet dont la version fait foi, par framework (lu dans le composer.lock de l'environnement). */
    private const array FRAMEWORK_PACKAGES = ['symfony' => 'symfony/framework-bundle', 'laravel' => 'laravel/framework'];

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
        $package = self::FRAMEWORK_PACKAGES[$environment->framework] ?? null;
        if (null === $package) {
            $result->error(sprintf('« version » n\'a pas de sens pour l\'environnement « %s » (%s) : retirez-la.', $environment->id, $environment->framework));

            return;
        }
        $lock = json_decode((string) @file_get_contents($environment->directory.'/composer.lock'), true);
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
    private function checkStructure(Exercise $exercise, array $starting, array $tests, array $solution, string $environmentDir, CheckResult $result): void
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
            $pattern = 'nuxt' === $this->framework
                ? '/\b(?:it|test)(?:\.\w+)*\s*\(\s*([\'"`])'.preg_quote($objective->test, '/').'\1/u'
                : '/function\s+'.preg_quote($objective->test, '/').'\s*\(/';
            if (!preg_match($pattern, $testCode)) {
                $result->error(sprintf('Objectif « %s » : aucun %s de ce nom dans tests/.', $objective->test, 'nuxt' === $this->framework ? 'test it()' : 'méthode de test'));
            }
        }
        // Un motif (migrations/*.php) désigne des fichiers qui n'existent pas encore : rien à vérifier.
        foreach ([...$exercise->editablePaths(), ...$exercise->readonly] as $path) {
            if (!isset($starting[$path]) && !is_file($environmentDir.'/'.$path)) {
                $result->error(sprintf('Fichier « %s » introuvable (ni dans starter/, ni dans une base, ni dans l\'environnement).', $path));
            }
        }
        // Le fichier ouvert au démarrage, quand seul un motif le couvre, doit faire partie de l'état de départ.
        if (!\in_array($exercise->open, [...$exercise->editablePaths(), ...$exercise->readonly], true) && !isset($starting[$exercise->open])) {
            $result->error(sprintf('« open » (%s) : ce fichier n\'existe pas au départ (ni dans starter/, ni dans une base).', $exercise->open));
        }
        foreach ($exercise->mutants as $mutant) {
            foreach ($mutant->changes as $change) {
                $code = $solution[$change['file']] ?? $starting[$change['file']] ?? (is_file($environmentDir.'/'.$change['file']) ? (string) file_get_contents($environmentDir.'/'.$change['file']) : null);
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
        if ('nuxt' === $this->framework) {
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
