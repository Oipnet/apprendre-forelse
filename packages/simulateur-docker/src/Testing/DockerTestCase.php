<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Testing;

use Forelse\DockerSim\Build\BuildResult;
use Forelse\DockerSim\Cli\Application;
use Forelse\DockerSim\Compose\ComposeException;
use Forelse\DockerSim\Compose\ComposeFile;
use Forelse\DockerSim\Compose\Project;
use Forelse\DockerSim\Dockerfile\Dockerfile;
use Forelse\DockerSim\Dockerfile\Instruction;
use Forelse\DockerSim\Dockerfile\ParseError;
use Forelse\DockerSim\Dockerfile\Parser;
use Forelse\DockerSim\Engine\Docker;
use Forelse\DockerSim\Http\HttpResponse;
use Forelse\DockerSim\State\Container;
use Forelse\DockerSim\State\Image;
use Forelse\DockerSim\State\Store;
use PHPUnit\Framework\TestCase;

/**
 * Base des tests d'exercices Docker : chaque test part d'un démon vierge (état dans un dossier
 * temporaire) sur le projet de l'apprenant, et dispose d'outils pour construire, lancer, interroger.
 *
 *     $build = $this->build('criee');            // docker build -t criee .
 *     $this->assertBuildSucceeded($build);
 *     $this->docker('run -d -p 8080:80 criee');   // n'importe quelle commande docker
 *     $this->assertPageContains('localhost:8080/', 'La Criée');
 */
abstract class DockerTestCase extends TestCase
{
    private ?Docker $engine = null;
    private ?string $state = null;

    protected function projectDirectory(): string
    {
        $configured = getenv('DOCKER_SIM_PROJECT');
        if ($configured !== false && $configured !== '') {
            return $configured;
        }
        // tests/…/QuelqueChoseTest.php => racine du projet : le dossier qui contient tests/.
        $directory = \dirname((new \ReflectionClass($this))->getFileName() ?: getcwd());
        while ($directory !== '/' && basename($directory) !== 'tests') {
            $directory = \dirname($directory);
        }

        return $directory === '/' ? (string) getcwd() : \dirname($directory);
    }

    protected function engine(): Docker
    {
        if ($this->engine === null) {
            $this->state = rtrim(sys_get_temp_dir(), '/').'/docker-sim-test-'.bin2hex(random_bytes(6));
            $this->engine = new Docker($this->state, $this->projectDirectory());
        }

        return $this->engine;
    }

    protected function tearDown(): void
    {
        if ($this->state !== null) {
            Store::removeTree($this->state);
        }
        $this->engine = null;
        $this->state = null;
        parent::tearDown();
    }

    // --- Fichiers du projet -----------------------------------------------------------------

    protected function projectFile(string $path): ?string
    {
        $file = $this->projectDirectory().'/'.ltrim($path, '/');

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    /** Le Dockerfile analysé ; le test échoue avec le message de BuildKit s'il est invalide. */
    protected function dockerfile(string $path = 'Dockerfile'): Dockerfile
    {
        $content = $this->projectFile($path);
        if ($content === null) {
            $this->fail(sprintf('Le fichier %s n\'existe pas.', $path));
        }
        try {
            return (new Parser())->parse($content);
        } catch (ParseError $e) {
            $this->fail($e->getMessage());
        }
    }

    /** @return list<Instruction> les instructions d'un type, toutes étapes confondues */
    protected function instructions(string $name, string $path = 'Dockerfile'): array
    {
        $result = [];
        foreach ($this->dockerfile($path)->stages as $stage) {
            foreach ($stage->instructions as $instruction) {
                if ($instruction->name === strtoupper($name)) {
                    $result[] = $instruction;
                }
            }
        }

        return $result;
    }

    protected function compose(?string $file = null): Project
    {
        try {
            return new Project($this->engine(), ComposeFile::load($this->projectDirectory(), $file));
        } catch (ComposeException $e) {
            $this->fail('docker compose : '.$e->getMessage());
        }
    }

    // --- Actions -----------------------------------------------------------------------------

    /** @param array<string,string> $buildArgs */
    protected function build(?string $tag = null, ?string $target = null, array $buildArgs = [], string $context = '.', ?string $dockerfile = null): BuildResult
    {
        return $this->engine()->build($context, $dockerfile, $tag !== null ? [$tag] : [], $target, $buildArgs);
    }

    /**
     * Une commande docker, comme dans la console : « run -d -p 8080:80 criee », « compose up -d ».
     *
     * @return array{0: int, 1: string} code de sortie, sortie
     */
    protected function docker(string $command): array
    {
        $application = new Application($this->state ?? $this->engine()->store->directory, $this->projectDirectory());
        // Le même démon : l'application relit l'état sauvegardé.
        $this->engine()->save();
        $code = $application->run(ComposeFile::shellSplit($command));
        $this->engine = new Docker((string) $this->state, $this->projectDirectory());

        return [$code, $application->output];
    }

    /**
     * Exécute un script de commandes docker du projet (lancer.sh) : ligne à ligne, arrêt au premier échec.
     *
     * @return array{0: int, 1: string}
     */
    protected function runScript(string $path): array
    {
        $script = $this->projectFile($path);
        if ($script === null) {
            $this->fail(sprintf('Le fichier %s n\'existe pas.', $path));
        }
        if (\Forelse\DockerSim\Cli\ScriptRunner::commands($script) === [] || array_filter(\Forelse\DockerSim\Cli\ScriptRunner::commands($script), static fn ($c) => !str_starts_with($c, '#')) === []) {
            $this->fail(sprintf('%s ne contient aucune commande docker.', $path));
        }
        $this->engine()->save();
        $application = new Application((string) $this->state, $this->projectDirectory());
        $result = \Forelse\DockerSim\Cli\ScriptRunner::run($application, $script);
        $this->engine = new Docker((string) $this->state, $this->projectDirectory());

        return $result;
    }

    /** Comme runScript(), mais le test échoue si une commande échoue. */
    protected function runScriptOk(string $path): string
    {
        [$code, $output] = $this->runScript($path);
        $this->assertSame(0, $code, sprintf("Une commande de %s a échoué (code %d) :\n%s", $path, $code, self::tail($output)));

        return $output;
    }

    /** Comme docker(), mais le test échoue si la commande échoue. */
    protected function dockerOk(string $command): string
    {
        [$code, $output] = $this->docker($command);
        $this->assertSame(0, $code, sprintf("« docker %s » a échoué (code %d) :\n%s", $command, $code, self::tail($output)));

        return $output;
    }

    protected function http(string $url, string $method = 'GET', array $headers = [], string $body = ''): HttpResponse
    {
        return $this->engine()->http($method, $url, $headers, $body);
    }

    protected function container(string $name): Container
    {
        $container = $this->engine()->store->findContainer($name);
        if ($container === null) {
            $this->fail(sprintf('Aucun conteneur « %s ».', $name));
        }

        return $container;
    }

    /** Le conteneur d'un service compose. */
    protected function service(string $service): Container
    {
        $container = $this->compose()->containers($service)[0] ?? null;
        if ($container === null) {
            $this->fail(sprintf('Le service « %s » n\'a pas de conteneur (docker compose up a-t-il réussi ?).', $service));
        }

        return $container;
    }

    protected function image(string $reference): Image
    {
        $image = $this->engine()->store->findImage($reference);
        if ($image === null) {
            $this->fail(sprintf('Aucune image « %s ».', $reference));
        }

        return $image;
    }

    /** @return array{0: int, 1: string} */
    protected function exec(Container $container, string $command, ?string $user = null): array
    {
        return $this->engine()->exec($container, ['sh', '-c', $command], $user);
    }

    // --- Assertions --------------------------------------------------------------------------

    protected function assertBuildSucceeded(BuildResult $build, string $message = ''): void
    {
        $this->assertTrue($build->success, ($message !== '' ? $message."\n" : '')."Le build a échoué :\n".self::tail($build->output));
    }

    protected function assertBuildFailed(BuildResult $build, string $message = ''): void
    {
        $this->assertFalse($build->success, $message !== '' ? $message : 'Le build aurait dû échouer.');
    }

    protected function assertImageHasFile(Image $image, string $path, string $message = ''): void
    {
        $this->assertArrayHasKey($path, $image->filesystem(), $message !== '' ? $message : sprintf('L\'image ne contient pas %s.', $path));
    }

    protected function assertImageLacksFile(Image $image, string $path, string $message = ''): void
    {
        foreach (array_keys($image->filesystem()) as $file) {
            if ($file === $path || str_starts_with($file, rtrim($path, '/').'/')) {
                $this->fail($message !== '' ? $message : sprintf('L\'image contient %s, qui ne devrait pas y être.', $file));
            }
        }
        $this->addToAssertionCount(1);
    }

    protected function assertImageSizeBelow(Image $image, int $megabytes, string $message = ''): void
    {
        $this->assertLessThan($megabytes * 1_000_000, $image->size(), $message !== '' ? $message : sprintf('L\'image pèse %d Mo : elle devrait rester sous %d Mo.', intdiv($image->size(), 1_000_000), $megabytes));
    }

    protected function assertRunning(Container $container, string $message = ''): void
    {
        $this->assertTrue($container->isRunning(), $message !== '' ? $message : sprintf("Le conteneur %s ne tourne pas (%s, code %d). Ses journaux :\n%s", $container->name, $container->status, $container->exitCode, self::tail(implode("\n", $container->logs))));
    }

    protected function assertPageContains(string $url, string $text, string $message = ''): HttpResponse
    {
        $response = $this->http($url);
        $this->assertNull($response->error, ($message !== '' ? $message."\n" : '').sprintf('%s ne répond pas : %s', $url, implode(' ', $response->trace)));
        $this->assertStringContainsString($text, $response->body, ($message !== '' ? $message."\n" : '').sprintf("La page %s (HTTP %d) ne contient pas « %s ».\n%s", $url, $response->status, $text, self::tail(strip_tags($response->body), 8)));

        return $response;
    }

    protected static function tail(string $text, int $lines = 25): string
    {
        $all = explode("\n", rtrim($text, "\n"));

        return implode("\n", \array_slice($all, -$lines));
    }
}
