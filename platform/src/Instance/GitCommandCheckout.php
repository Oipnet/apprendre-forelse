<?php

namespace App\Instance;

use App\Content\ContentException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Process\ExecutableFinder;

/** GitCheckout par la commande git du serveur. */
#[AsAlias(GitCheckout::class)]
final readonly class GitCommandCheckout implements GitCheckout
{
    private const int CLONE_TIMEOUT = 300;

    public function __construct(
        private CommandRunner $runner = new CommandRunner(),
    ) {
    }

    public function clone(string $url, string $ref, string $destination): void
    {
        if (null === (new ExecutableFinder())->find('git')) {
            throw new ContentException('git est introuvable sur ce serveur : impossible d\'installer un environnement depuis un dépôt.');
        }
        // Tableau d'arguments, jamais de shell : une adresse ne peut pas devenir une commande.
        $command = ['git', 'clone', '--depth', '1', '--single-branch', '--no-tags'];
        if ('' !== $ref) {
            $command[] = '--branch';
            $command[] = $ref;
        }
        $this->runner->run([...$command, '--', $url, $destination], null, self::CLONE_TIMEOUT, 'Clonage');
    }

    public function commit(string $directory): string
    {
        return trim($this->runner->run(['git', 'rev-parse', 'HEAD'], $directory, 30, 'Lecture du commit'));
    }
}
