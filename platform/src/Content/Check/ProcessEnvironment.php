<?php

namespace App\Content\Check;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Dotenv\Dotenv;

/**
 * L'environnement des processus qui lancent les tests d'un projet : sans les variables de la plateforme.
 */
final readonly class ProcessEnvironment
{
    public function __construct(
        /** Dossier de la plateforme : son .env déclare les variables à cacher au projet testé. */
        #[Autowire('%kernel.project_dir%')]
        private string $platformDir = __DIR__.'/../../..',
    ) {
    }

    /**
     * Le projet testé doit charger ses propres .env : on lui cache les variables de la plateforme
     * (Symfony Process transmet l'environnement sinon). Deux sources, parce qu'elles diffèrent :
     * en développement, le Dotenv de la plateforme les a injectées (SYMFONY_DOTENV_VARS) ; en
     * production (Docker), elles sont de vraies variables d'environnement, absentes de cette liste.
     * D'où la lecture des noms déclarés dans le .env de la plateforme. Sans cela, DATABASE_URL
     * de la production atteint les tests d'un exercice Doctrine, qui cherchent alors « formation_test ».
     *
     * APP_DEBUG est fixé à 0, comme dans le navigateur : la page d'erreur du mode debug cite le code source, celui
     * du test compris, et un test qui cherche un texte dans la page passerait alors sur une 404.
     *
     * @param array<string, mixed> $server $_SERVER par défaut
     *
     * @return array<string, string|false>
     */
    public function isolated(?array $server = null): array
    {
        $server ??= $_SERVER;
        $env = ['APP_ENV' => 'test', 'APP_DEBUG' => '0', 'SYMFONY_DOTENV_VARS' => false, 'SYMFONY_DOTENV_PATH' => false];

        $declared = is_file($this->platformDir.'/.env') ? array_keys((new Dotenv())->parse((string) file_get_contents($this->platformDir.'/.env'))) : [];
        $injected = explode(',', (string) ($server['SYMFONY_DOTENV_VARS'] ?? ''));
        foreach ([...$declared, ...$injected, 'DATABASE_URL'] as $name) {
            if ('' !== $name && !\in_array($name, ['APP_ENV', 'APP_DEBUG'], true)) {
                $env[$name] = false;
            }
        }

        return $env;
    }
}
