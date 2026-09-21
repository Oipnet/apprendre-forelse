<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Tests;

use Forelse\DockerSim\Cli\Application;
use Forelse\DockerSim\Engine\Docker;
use Forelse\DockerSim\State\Store;
use PHPUnit\Framework\TestCase;

/** Un projet jetable (fichiers de l'hôte) et un démon vierge pour chaque test. */
abstract class SimulatorTestCase extends TestCase
{
    protected string $project;
    protected string $state;

    protected function setUp(): void
    {
        $root = rtrim(sys_get_temp_dir(), '/').'/docker-sim-unit-'.bin2hex(random_bytes(5));
        $this->project = $root.'/criee';
        $this->state = $root.'/state';
        mkdir($this->project, 0777, true);
    }

    protected function tearDown(): void
    {
        Store::removeTree(\dirname($this->project));
    }

    /** @param array<string,string> $files */
    protected function files(array $files): void
    {
        foreach ($files as $path => $content) {
            $target = $this->project.'/'.$path;
            @mkdir(\dirname($target), 0777, true);
            file_put_contents($target, $content);
        }
    }

    protected function docker(): Docker
    {
        return new Docker($this->state, $this->project);
    }

    /** @return array{0: int, 1: string} */
    protected function cli(string $command): array
    {
        $application = new Application($this->state, $this->project);
        $code = $application->run(\Forelse\DockerSim\Compose\ComposeFile::shellSplit($command));

        return [$code, $application->output];
    }

    protected function cliOk(string $command): string
    {
        [$code, $output] = $this->cli($command);
        $this->assertSame(0, $code, "docker {$command}\n{$output}");

        return $output;
    }

    /**
     * Rejoue un script de commandes docker, comme « sh lancer.sh » dans la console.
     *
     * @return array{0: int, 1: string}
     */
    protected function script(string $file): array
    {
        $application = new Application($this->state, $this->project);

        return \Forelse\DockerSim\Cli\ScriptRunner::run($application, (string) file_get_contents($this->project.'/'.$file));
    }

    protected function page(string $url): string
    {
        $response = $this->docker()->http('GET', $url);
        $this->assertNull($response->error, implode(' ', $response->trace));

        return $response->body;
    }
}
