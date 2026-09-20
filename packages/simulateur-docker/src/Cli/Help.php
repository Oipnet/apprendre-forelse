<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Cli;

final class Help
{
    public const MAIN = <<<'TXT'

        Usage:  docker [OPTIONS] COMMAND

        A self-sufficient runtime for containers
        (simulateur forelse : tout se passe dans votre navigateur)

        Common Commands:
          run         Create and run a new container from an image
          exec        Execute a command in a running container
          ps          List containers
          build       Build an image from a Dockerfile
          pull        Download an image from a registry
          images      List images
          compose*    Docker Compose

        Management Commands:
          container   Manage containers
          image       Manage images
          network     Manage networks
          system      Manage Docker
          volume      Manage volumes

        Commands:
          cp          Copy files/folders between a container and the local filesystem
          create      Create a new container
          history     Show the history of an image
          inspect     Return low-level information on Docker objects
          kill        Kill one or more running containers
          logs        Fetch the logs of a container
          port        List port mappings or a specific mapping for the container
          restart     Restart one or more containers
          rm          Remove one or more containers
          rmi         Remove one or more images
          start       Start one or more stopped containers
          stop        Stop one or more running containers
          tag         Create a tag TARGET_IMAGE that refers to SOURCE_IMAGE
          top         Display the running processes of a container

        Run 'docker COMMAND --help' for more information on a command.
        TXT;

    /** @param list<string> $argv */
    public static function command(string $command, array $argv): string
    {
        return match ($command) {
            'run' => "\nUsage:  docker run [OPTIONS] IMAGE [COMMAND] [ARG...]\n\nCreate and run a new container from an image\n\nOptions:\n  -d, --detach                 Run container in background and print container ID\n      --entrypoint string      Overwrite the default ENTRYPOINT of the image\n  -e, --env list               Set environment variables\n      --env-file list          Read in a file of environment variables\n  -i, --interactive            Keep STDIN open even if not attached\n      --name string            Assign a name to the container\n      --network network        Connect a container to a network\n  -p, --publish list           Publish a container's port(s) to the host\n  -P, --publish-all            Publish all exposed ports to random ports\n      --restart string         Restart policy to apply when a container exits (default \"no\")\n      --rm                     Automatically remove the container and its associated anonymous volumes when it exits\n  -t, --tty                    Allocate a pseudo-TTY\n  -u, --user string            Username or UID (format: \"<name|uid>[:<group|gid>]\")\n  -v, --volume list            Bind mount a volume\n  -w, --workdir string         Working directory inside the container",
            'build' => "\nUsage:  docker buildx build [OPTIONS] PATH | URL | -\n\nStart a build\n\nOptions:\n      --build-arg stringArray     Set build-time variables\n  -f, --file string               Name of the Dockerfile (default: \"PATH/Dockerfile\")\n      --no-cache                  Do not use cache when building the image\n  -q, --quiet                     Suppress the build output and print image ID on succeed\n  -t, --tag stringArray           Name and optionally a tag (format: \"name:tag\")\n      --target string             Set the target build stage to build",
            'exec' => "\nUsage:  docker exec [OPTIONS] CONTAINER COMMAND [ARG...]\n\nExecute a command in a running container\n\nOptions:\n  -d, --detach               Detached mode: run command in the background\n  -e, --env list             Set environment variables\n  -i, --interactive          Keep STDIN open even if not attached\n  -t, --tty                  Allocate a pseudo-TTY\n  -u, --user string          Username or UID (format: \"<name|uid>[:<group|gid>]\")\n  -w, --workdir string       Working directory inside the container",
            'compose' => "\nUsage:  docker compose [OPTIONS] COMMAND\n\nDefine and run multi-container applications with Docker\n\nOptions:\n      --env-file stringArray     Specify an alternate environment file\n  -f, --file stringArray         Compose configuration files\n  -p, --project-name string      Project name\n\nCommands:\n  build       Build or rebuild services\n  config      Parse, resolve and render compose file in canonical format\n  down        Stop and remove containers, networks\n  exec        Execute a command in a running container\n  images      List images used by the created containers\n  logs        View output from containers\n  ls          List running compose projects\n  ps          List containers\n  pull        Pull service images\n  restart     Restart service containers\n  run         Run a one-off command on a service\n  start       Start services\n  stop        Stop services\n  up          Create and start containers\n  version     Show the Docker Compose version information",
            default => sprintf("\nUsage:  docker %s [OPTIONS]\n\n(aide détaillée non disponible dans le simulateur : voir https://docs.docker.com/reference/cli/docker/%s/)", $command, $command),
        };
    }
}
