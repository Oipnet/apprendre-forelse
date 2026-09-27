<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell\Command;

use Forelse\DockerSim\Shell\Interpreter;
use Forelse\DockerSim\Shell\Machine;
use Forelse\DockerSim\Shell\Result;

/**
 * Socle des utilitaires de base (coreutils / busybox) : chaque groupe écrit ses erreurs à la façon
 * de BusyBox, traduites ici pour Debian.
 */
abstract class CoreutilsCommands implements Command
{
    final public function run(string $name, array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        $result = $this->dispatch($name, $args, $m, $stdin, $sh);
        if ($m->facts->os === 'alpine' || $result->stderr === '') {
            return $result;
        }

        return new Result($result->code, $result->stdout, self::coreutils($result->stderr), $result->seconds);
    }

    /**
     * Les messages d'erreur sont écrits à la façon de BusyBox (Alpine). Debian a les coreutils de GNU,
     * qui ne disent pas les choses de la même manière : on traduit, pour qu'un apprenant qui compare
     * avec sa machine retrouve exactement ce que son terminal affiche.
     */
    public static function coreutils(string $stderr): string
    {
        return (string) preg_replace(
            [
                "/^mkdir: can't create directory '([^']*)': /m",
                "/^rm: can't remove '([^']*)': /m",
                "/^cp: can't stat '([^']*)': /m",
                "/^mv: can't stat '([^']*)': /m",
                "/^cp: can't create '([^']*)': /m",
                "/^touch: ([^:\n]+): (No such file or directory|Permission denied|Read-only file system)$/m",
                "/^ls: ([^:\n]+): No such file or directory$/m",
                "/^(chmod|chown|chgrp): ([^:\n]+): No such file or directory$/m",
                "/^sed: ([^:\n]+): No such file or directory$/m",
                "/^find: ([^:\n]+): No such file or directory$/m",
                "/^stat: can't stat '([^']*)': /m",
                "/^cp: omitting directory '([^']*)'$/m",
                "/^chown: ([^:\\n]+): Operation not permitted$/m",
                "/^chmod: ([^:\\n]+): Operation not permitted$/m",
            ],
            [
                "mkdir: cannot create directory '$1': ",
                "rm: cannot remove '$1': ",
                "cp: cannot stat '$1': ",
                "mv: cannot stat '$1': ",
                "cp: cannot create regular file '$1': ",
                "touch: cannot touch '$1': $2",
                "ls: cannot access '$1': No such file or directory",
                "$1: cannot access '$2': No such file or directory",
                "sed: can't read $1: No such file or directory",
                "find: '$1': No such file or directory",
                "stat: cannot statx '$1': ",
                "cp: -r not specified; omitting directory '$1'",
                "chown: changing ownership of '$1': Operation not permitted",
                "chmod: changing permissions of '$1': Operation not permitted",
            ],
            $stderr,
        );
    }

    /**
     * Exécute la commande $name ; un nom déclaré dans names() sans implémentation lève une LogicException.
     *
     * @param list<string> $args
     */
    abstract protected function dispatch(string $name, array $args, Machine $m, string $stdin, Interpreter $sh): Result;
}
