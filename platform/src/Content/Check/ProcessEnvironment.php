<?php

namespace App\Content\Check;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

/**
 * L'environnement des processus qui lancent les tests d'un projet : sans les variables de la plateforme et, dans le
 * service « verificateur », sous un autre utilisateur que celui qui note.
 *
 * Avec VERIFICATION_RUNNER_UID, les tests (donc le code de l'apprenant) tournent sous cet utilisateur, et le processus
 * qui lit leur rapport et rend le verdict (`app:verification:rejouer`, voir VerificationSandbox) sous un autre. Le code
 * testé ne peut alors ni écrire dans la sortie de celui qui note, ni le tuer, ni toucher à son cache. Il reste maître
 * de son propre processus : un code qui vise le framework de test peut toujours tricher, mais plus en réécrivant un
 * fichier après coup.
 */
final readonly class ProcessEnvironment
{
    public function __construct(
        /** Dossier de la plateforme : son .env déclare les variables à cacher au projet testé. */
        #[Autowire('%kernel.project_dir%')]
        private string $platformDir = __DIR__.'/../../..',
        #[Autowire(env: 'default::int:VERIFICATION_RUNNER_UID')]
        private ?int $runnerUid = null,
    ) {
    }

    /**
     * La commande des tests, sous l'utilisateur des tests s'il y en a un.
     *
     * @param list<string> $command
     *
     * @return list<string>
     */
    public function command(array $command): array
    {
        return null === $this->runnerUid
            ? $command
            : ['setpriv', '--reuid='.$this->runnerUid, '--regid='.$this->runnerUid, '--clear-groups', '--inh-caps=-all', '--ambient-caps=-all', '--no-new-privs', ...$command];
    }

    /**
     * Avant chaque lancement : le projet, écrit par l'utilisateur qui note, ouvert en écriture à celui des tests (caches,
     * var/…). Les liens symboliques sont laissés tels quels : un chmod les suivrait hors du projet.
     */
    public function open(string $workdir): void
    {
        if (null === $this->runnerUid) {
            return;
        }
        $paths = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($workdir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ([new \SplFileInfo($workdir), ...$paths] as $path) {
            if (!$path->isLink()) {
                @chmod($path->getPathname(), $path->isDir() ? 0o777 : ($path->getPerms() & 0o777) | 0o666);
            }
        }
    }

    /**
     * Après chaque lancement : plus aucun processus de l'utilisateur des tests. Ce qu'il aurait laissé en arrière-plan ne
     * peut pas modifier le projet pendant que celui qui note y lit ou y écrit (mutants, rapport).
     */
    public function close(): void
    {
        if (null !== $this->runnerUid) {
            // kill(-1) : tous les processus que l'appelant peut tuer, donc ceux de son utilisateur, sauf lui-même.
            (new Process($this->command([\PHP_BINARY, '-r', 'posix_kill(-1, 9);']), timeout: 30))->run();
        }
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
