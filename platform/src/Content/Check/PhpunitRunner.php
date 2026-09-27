<?php

namespace App\Content\Check;

use App\Content\Environment;
use App\Content\Framework\FrameworkProfile;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

/** PHPUnit, avec le PHP natif et le vendor/ de l'environnement ; le rapport est lu dans son JUnit. */
#[AsTaggedItem(FrameworkProfile::PHPUNIT)]
final readonly class PhpunitRunner implements TestRunner
{
    public function __construct(
        private ProcessEnvironment $processEnvironment,
        private Filesystem $filesystem = new Filesystem(),
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
        $junit = $workdir.'/var/junit.xml';
        $this->filesystem->remove($junit);
        // Xdebug désactivé : inutile ici, plus lent, et il a déjà fait planter PHP sur des pages d'erreur.
        // 512 Mo : un exercice qui manipule beaucoup de fichiers (parcours Docker) dépasse les 128 Mo par défaut.
        $process = new Process([\PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'memory_limit=512M', 'vendor/bin/phpunit', '--colors=never', '--do-not-cache-result', '--log-junit', $junit, ...$paths], $workdir, $this->processEnvironment->isolated(), timeout: 120);
        try {
            $process->run();
        } catch (ProcessSignaledException $e) {
            // Crash de PHP (ex. récursion infinie) : signalé comme une absence de rapport.
            return RunReport::none(sprintf('PHP a planté (signal %d).', $e->getSignal()));
        }
        if (!is_file($junit)) {
            // Pas de rapport (erreur fatale au chargement, le plus souvent) : la fin de la sortie dit pourquoi.
            $sortie = trim($process->getErrorOutput()."\n".$process->getOutput());

            return RunReport::none('' !== $sortie ? implode("\n", \array_slice(explode("\n", $sortie), -5)) : '(erreur fatale ?)');
        }

        return self::parse((string) file_get_contents($junit));
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
