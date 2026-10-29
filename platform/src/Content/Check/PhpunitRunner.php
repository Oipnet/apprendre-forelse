<?php

namespace App\Content\Check;

use App\Content\Environment;
use App\Content\Framework\FrameworkProfile;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

/** PHPUnit, avec le PHP natif et le vendor/ de l'environnement ; le rapport est lu dans son JUnit, sur la sortie d'erreur. */
#[AsTaggedItem(FrameworkProfile::PHPUNIT)]
final readonly class PhpunitRunner implements TestRunner
{
    public function __construct(
        private ProcessEnvironment $processEnvironment,
        /** Où build-env.sh écrit les catalogues de paquets et leurs archives (voir composerInstall). */
        #[Autowire('%kernel.project_dir%/public')]
        private string $publicDir = __DIR__.'/../../../public',
    ) {
    }

    public function unavailable(Environment $environment): ?string
    {
        return null === $environment->file('vendor/autoload.php')
            ? sprintf('Environnement « %s » sans vendor/ : lancez environments/bin/build-env.sh %s.', $environment->id, $environment->id)
            : null;
    }

    public function declares(string $testCode, string $test): bool
    {
        return 1 === preg_match('/function\s+'.preg_quote($test, '/').'\s*\(/', $testCode);
    }

    public function testNoun(): string
    {
        return 'méthode de test';
    }

    public function run(Environment $environment, string $workdir, array $paths = []): RunReport
    {
        // Le rapport JUnit passe par la sortie d'erreur, pas par un fichier du projet : le code testé ne peut plus le
        // réécrire après PHPUnit (une fonction d'arrêt qui remplace var/junit.xml suffisait à tout réussir). Un second
        // rapport dans la même sortie rend le verdict nul.
        // Xdebug désactivé : inutile ici, plus lent, et il a déjà fait planter PHP sur des pages d'erreur.
        // 512 Mo : un exercice qui manipule beaucoup de fichiers (parcours Docker) dépasse les 128 Mo par défaut.
        $command = [\PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'memory_limit=512M', 'vendor/bin/phpunit', '--colors=never', '--do-not-cache-result', '--log-junit', 'php://stderr', ...$paths];
        $this->processEnvironment->open($workdir);
        $process = new Process($this->processEnvironment->command($command), $workdir, $this->processEnvironment->isolated(), timeout: 120);
        try {
            if (null !== ($echec = $this->composerInstall($environment, $workdir))) {
                return RunReport::none($echec);
            }
            $process->run();
        } catch (ProcessSignaledException $e) {
            // Crash de PHP (ex. récursion infinie) : signalé comme une absence de rapport.
            return RunReport::none(sprintf('PHP a planté (signal %d).', $e->getSignal()));
        } finally {
            $this->processEnvironment->close();
        }
        [$junit, $errors] = self::extract($process->getErrorOutput());
        if (null === $junit) {
            return RunReport::none('Plusieurs rapports de tests : le verdict a été falsifié.');
        }
        // Pas de rapport, ou un rapport vide (erreur fatale au chargement ou en cours de route, comme une méthode de
        // test qui en masque une de PHPUnit) : la fin de la sortie dit pourquoi.
        if ('' === $junit) {
            $sortie = trim($errors."\n".$process->getOutput());

            return RunReport::none('' !== $sortie ? implode("\n", \array_slice(explode("\n", $sortie), -5)) : '(erreur fatale ?)');
        }

        return self::parse($junit);
    }

    /**
     * Projet sans framework (environments/php-8/.forelse/composer.php) : son composer.json a pu changer d'autoload ou
     * demander des paquets du catalogue (`composer require` dans le navigateur). On les pose et on régénère l'autoload,
     * comme un `composer install`, sinon les tests tourneraient sur le vendor/ figé de l'environnement.
     *
     * @return ?string la raison d'un échec, null si tout est en place
     */
    private function composerInstall(Environment $environment, string $workdir): ?string
    {
        if (!is_file($workdir.'/.forelse/composer.php') || !is_file($workdir.'/composer.json')) {
            return null;
        }
        $env = $this->processEnvironment->isolated();
        $catalogue = $environment->packageCatalogPath();
        if (null !== $catalogue && is_file($this->publicDir.'/'.$catalogue)) {
            $env['FORELSE_PAQUETS'] = $this->publicDir.'/'.$catalogue;
        }
        $install = new Process($this->processEnvironment->command([\PHP_BINARY, '.forelse/composer.php', 'install', '--quiet']), $workdir, $env, timeout: 60);
        $install->run();

        return $install->isSuccessful() ? null : 'composer install : '.trim($install->getOutput()."\n".$install->getErrorOutput());
    }

    /**
     * Le rapport JUnit dans la sortie d'erreur, et le reste de cette sortie. Rapport vide s'il n'y en a pas, null s'il y
     * en a plusieurs : PHPUnit n'en écrit qu'un, le second vient du code testé.
     *
     * @return array{0: ?string, 1: string}
     */
    public static function extract(string $stderr): array
    {
        $count = substr_count($stderr, '<testsuites');
        if (0 === $count) {
            return ['', $stderr];
        }
        $start = (int) strpos($stderr, '<testsuites');
        $end = strrpos($stderr, '</testsuites>');
        if (1 !== $count || false === $end || $end < $start) {
            return [null, $stderr];
        }
        $end += \strlen('</testsuites>');
        // La déclaration XML qui précède, si elle y est, n'est pas du « reste ».
        $declaration = strrpos(substr($stderr, 0, $start), '<?xml');
        $before = false !== $declaration ? substr($stderr, 0, $declaration) : substr($stderr, 0, $start);

        return [substr($stderr, $start, $end - $start), $before.substr($stderr, $end)];
    }

    /** Le rapport JUnit de PHPUnit, par nom de test. */
    public static function parse(string $junit): RunReport
    {
        $cases = [];
        $failures = [];
        foreach (simplexml_load_string($junit)->xpath('//testcase') ?: [] as $testcase) {
            // SimpleXML renvoie un élément vide (jamais null) pour une balise absente : isset() obligatoire.
            $failure = isset($testcase->failure) ? $testcase->failure : (isset($testcase->error) ? $testcase->error : null);
            $cases[(string) $testcase['name']] = [
                'status' => null !== $failure ? 'failed' : (isset($testcase->skipped) ? 'skipped' : 'passed'),
                'file' => (string) $testcase['file'],
            ];
            if (null !== $failure) {
                $lines = array_filter(explode("\n", (string) $failure), static fn ($l) => '' !== trim($l) && !str_starts_with($l, '/'));
                $failures[(string) $testcase['name']] = implode(' ', \array_slice($lines, 1, 2));
            }
        }

        return RunReport::of($cases, $failures);
    }
}
