<?php

/**
 * Correctif du vendor de l'environnement symfony-8-ia, appliqué par build-env.sh après composer install.
 *
 * Le SDK MCP (mcp/sdk) traite chaque requête dans une \Fiber, pour qu'un outil puisse suspendre son
 * travail le temps de poser une question au client (elicitation, sampling, notifications). PHP compilé
 * en WebAssembly (php-wasm, navigateur et vérificateur) n'a pas les Fibers : la première « new \Fiber »
 * arrête tout le processus (« missing function »). On remplace donc \Fiber, dans le SDK seulement, par
 * une fibre synchrone : le handler s'exécute d'une traite, sans pouvoir se suspendre. Outils,
 * ressources et prompts marchent ; elicitation et sampling lèvent une exception claire.
 *
 * Idempotent : relancé sur un vendor déjà corrigé, il ne change rien.
 */

$sdk = ($argv[1] ?? __DIR__.'/../symfony-8-ia').'/vendor/mcp/sdk/src';
if (!is_dir($sdk)) {
    fwrite(STDERR, "mcp/sdk introuvable dans $sdk\n");
    exit(1);
}

file_put_contents($sdk.'/FibreSynchrone.php', <<<'PHP'
<?php

namespace Mcp;

/**
 * Remplace \Fiber dans le SDK MCP de l'environnement symfony-8-ia (correctif Forelse) : php-wasm n'a pas
 * les Fibers. Le handler s'exécute d'une traite au start() ; il ne peut pas se suspendre.
 *
 * @internal
 */
final class FibreSynchrone
{
    private mixed $retour = null;
    private bool $terminee = false;

    public function __construct(private readonly \Closure|string|array $handler)
    {
    }

    public function start(mixed ...$arguments): mixed
    {
        $this->retour = ($this->handler)(...$arguments);
        $this->terminee = true;

        return null;
    }

    public function resume(mixed $valeur = null): mixed
    {
        throw new \LogicException('Une requête MCP ne peut pas être reprise dans le navigateur.');
    }

    public function throw(\Throwable $exception): mixed
    {
        throw $exception;
    }

    public function getReturn(): mixed
    {
        return $this->retour;
    }

    public function isStarted(): bool
    {
        return $this->terminee;
    }

    public function isSuspended(): bool
    {
        return false;
    }

    public function isRunning(): bool
    {
        return false;
    }

    public function isTerminated(): bool
    {
        return $this->terminee;
    }

    public static function suspend(mixed $valeur = null): mixed
    {
        throw new \LogicException('Dans le navigateur, un outil MCP ne peut pas interroger le client (elicitation, sampling, notifications de progression) : PHP y fonctionne sans Fibers.');
    }

    public static function getCurrent(): ?self
    {
        return null;
    }
}
PHP);

$corriges = 0;
$fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sdk, FilesystemIterator::SKIP_DOTS));
foreach ($fichiers as $fichier) {
    if ('php' !== $fichier->getExtension() || 'FibreSynchrone.php' === $fichier->getFilename()) {
        continue;
    }
    $code = file_get_contents($fichier->getPathname());
    $nouveau = preg_replace('/\\\\Fiber\b/', '\\\\Mcp\\\\FibreSynchrone', $code);
    if ($nouveau !== $code) {
        file_put_contents($fichier->getPathname(), $nouveau);
        ++$corriges;
    }
}

echo "mcp/sdk : \\Fiber remplacée par Mcp\\FibreSynchrone dans $corriges fichier(s).\n";
