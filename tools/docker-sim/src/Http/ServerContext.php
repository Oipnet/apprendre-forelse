<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Http;

use Forelse\DockerSim\Fs\DiskFs;
use Forelse\DockerSim\Runtime\PhpExecutor;
use Forelse\DockerSim\Shell\Network;
use Forelse\DockerSim\State\Container;

/** Ce dont un serveur simulé a besoin pour répondre : ses fichiers, son réseau, le PHP, ses journaux. */
interface ServerContext
{
    public function fs(Container $container): DiskFs;

    public function network(Container $container): Network;

    public function php(): PhpExecutor;

    public function log(Container $container, string $line): void;

    /** Conteneur qui écoute sur ce port (vu depuis $from), et son processus. @return array{0: ?Container, 1: ?string, 2: string} conteneur, processus, état */
    public function upstream(Container $from, string $host, int $port): array;
}
