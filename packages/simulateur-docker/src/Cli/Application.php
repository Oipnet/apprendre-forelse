<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Cli;

use Forelse\DockerSim\Engine\Docker;
use Forelse\DockerSim\Engine\DockerException;

/**
 * La commande « docker » : les variables posées devant, l'aide, puis le domaine qui répond (images,
 * conteneurs, réseaux…). Les erreurs du démon sont affichées au format de la vraie CLI.
 */
final class Application
{
    public const VERSION = '28.4.0';

    public string $output = '';

    /**
     * Variables posées devant la commande (`APP_ENV=dev docker compose up`) : Compose les lit,
     * et elles l'emportent sur le fichier .env du projet.
     *
     * @var array<string,string>
     */
    public array $environment = [];

    public function __construct(public readonly Docker $docker)
    {
    }

    public static function forDirectories(string $stateDirectory, string $projectDirectory): self
    {
        return new self(new Docker($stateDirectory, $projectDirectory));
    }

    public static function defaultStateDirectory(string $projectDirectory): string
    {
        $configured = getenv('DOCKER_SIM_STATE');

        return $configured !== false && $configured !== '' ? $configured : rtrim(sys_get_temp_dir(), '/').'/docker-sim-'.substr(md5($projectDirectory), 0, 12);
    }

    /** @param list<string> $argv arguments sans « docker » */
    public function run(array $argv): int
    {
        $out = new Output($this->output);
        $this->environment = [];
        while ($argv !== [] && preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/s', $argv[0], $m)) {
            $this->environment[$m[1]] = $m[2];
            array_shift($argv);
        }
        if (($argv[0] ?? null) === 'docker') {
            array_shift($argv);
        }
        if (($argv[0] ?? null) === 'docker-compose') {
            $argv[0] = 'compose';
        }
        try {
            return $this->dispatch($argv, $out);
        } catch (UsageError $e) {
            $out->line('docker: '.$e->getMessage());

            return $e->exitCode;
        } catch (DockerException $e) {
            $out->line(($e->daemon ? 'Error response from daemon: ' : '').$e->getMessage());

            return $e->exitCode === 125 ? 1 : $e->exitCode;
        } finally {
            $this->docker->save();
        }
    }

    /** « docker: unknown command » ; pour une commande du shell (ls, cat…), l'endroit où la taper. */
    public static function unknown(Output $out, string $command): int
    {
        $out->line(sprintf("docker: unknown command: docker %s\n\nRun 'docker --help' for more information", $command));
        $premier = explode(' ', $command)[0];
        if (\in_array($premier, ['ls', 'cat', 'cd', 'pwd', 'mkdir', 'rm', 'cp', 'mv', 'vim', 'nano', 'php', 'composer', 'curl', 'grep', 'touch'], true)) {
            $out->line(sprintf("💡 Cette console ne comprend que les commandes docker. Pour « %s », deux chemins : l'explorateur de fichiers à gauche pour votre projet, ou « docker exec <conteneur> %s » pour l'intérieur d'un conteneur.", $premier, $command));
        }

        return 1;
    }

    /** @return array<string, CliCommand> par nom de commande */
    private function commands(): array
    {
        $inspect = new InspectCommand($this->docker);
        $commands = [];
        foreach ([
            new ImageCommands($this->docker, $inspect),
            new ContainerCommands($this->docker, $inspect),
            new NetworkCommands($this->docker, $inspect),
            new VolumeCommands($this->docker, $inspect),
            new SystemCommands($this->docker),
            $inspect,
            new ComposeCommand($this->docker, $this->environment),
        ] as $command) {
            foreach ($command->names() as $name) {
                $commands[$name] = $command;
            }
        }

        return $commands;
    }

    /** @param list<string> $argv */
    private function dispatch(array $argv, Output $out): int
    {
        $command = array_shift($argv);
        if ($command === null || \in_array($command, ['--help', '-h', 'help'], true)) {
            $out->line(Help::MAIN);

            return 0;
        }
        if ($command === '-v' || $command === '--version') {
            $out->line('Docker version '.self::VERSION.', build 249d679');

            return 0;
        }
        if (\in_array('--help', $argv, true) || \in_array('-h', $argv, true) && $command !== 'run' && $command !== 'exec') {
            $out->line(Help::command($command, $argv));

            return 0;
        }
        $handler = $this->commands()[$command] ?? null;
        if ($handler !== null) {
            return $handler->run($command, $argv, $out);
        }

        return match ($command) {
            'login' => self::simpleLine($out, 'Login Succeeded (simulateur : aucun registre n\'est contacté)'),
            'logout' => self::simpleLine($out, 'Removing login credentials for https://index.docker.io/v1/'),
            'init' => self::simpleLine($out, "docker init : l'assistant interactif n'est pas disponible dans le simulateur.\nÉcrivez le Dockerfile et le compose.yaml à la main : c'est l'objet du parcours.", 1),
            'stats' => self::simpleLine($out, '(simulateur : pas de mesure de CPU ni de mémoire)'),
            'attach', 'wait', 'pause', 'unpause', 'rename', 'update', 'commit', 'save', 'load', 'export', 'import', 'diff', 'events', 'context', 'swarm', 'service', 'stack', 'node', 'secret', 'config', 'plugin', 'trust', 'scout', 'manifest' => self::simpleLine($out, sprintf('docker %s : commande non simulée.', $command), 1),
            default => self::unknown($out, $command),
        };
    }

    private static function simpleLine(Output $out, string $text, int $code = 0): int
    {
        $out->line($text);

        return $code;
    }
}
