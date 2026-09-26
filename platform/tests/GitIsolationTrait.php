<?php

namespace App\Tests;

/**
 * Une configuration git propre au test. `git config --global` (redirection d'adresse, protocol.file.allow) doit
 * écrire dans un dossier du test, jamais dans le vrai ~/.gitconfig : protocol.file.allow=always y lèverait une
 * protection de git pour tous les dépôts de la machine.
 *
 * Symfony Process construit l'environnement des sous-processus à partir de $_ENV d'abord, puis de getenv() limité
 * aux clés de $_SERVER : un putenv() seul ne suffit pas quand PHP remplit $_ENV (variables_order=EGPCS, le défaut
 * sans php.ini). Les variables sont donc posées aux trois endroits, puis rétablies.
 */
trait GitIsolationTrait
{
    /** @var array<string, array{env: string|false, _ENV: ?string, _SERVER: mixed}> */
    private array $gitEnvironmentBefore = [];

    protected function isolateGitConfig(string $home): void
    {
        foreach (['HOME' => $home, 'XDG_CONFIG_HOME' => $home, 'GIT_CONFIG_GLOBAL' => $home.'/.gitconfig'] as $name => $value) {
            $this->gitEnvironmentBefore[$name] ??= ['env' => getenv($name), '_ENV' => $_ENV[$name] ?? null, '_SERVER' => $_SERVER[$name] ?? null];
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    protected function restoreGitConfig(): void
    {
        foreach ($this->gitEnvironmentBefore as $name => $before) {
            putenv(false === $before['env'] ? $name : $name.'='.$before['env']);
            if (null === $before['_ENV']) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $before['_ENV'];
            }
            if (null === $before['_SERVER']) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $before['_SERVER'];
            }
        }
        $this->gitEnvironmentBefore = [];
    }
}
