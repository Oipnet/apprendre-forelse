<?php

namespace App\Instance;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\PhpExecutableFinder;

/** La ligne `php bin/console …` d'une commande de l'application : un tableau d'arguments, jamais de shell. */
final readonly class ConsoleCommandLine
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        public string $projectDir,
    ) {
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    public function of(string $command, array $arguments): array
    {
        $php = (new PhpExecutableFinder())->find() ?: 'php';

        return [$php, $this->projectDir.'/bin/console', $command, ...$arguments];
    }
}
