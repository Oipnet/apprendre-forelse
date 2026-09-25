<?php

namespace App\Instance;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Demandes d'installation et d'empaquetage d'environnements, confiées au service « empaqueteur ».
 *
 * Empaqueter lance `composer install` sur un dépôt tiers, donc exécute son code (plugins Composer, scripts
 * d'autoload). Dans le conteneur de la plateforme, ce code lirait les secrets (clés Stripe et Anthropic,
 * base, APP_SECRET). Avec ENVIRONMENTS_BUILDER=empaqueteur, la plateforme ne fait que déposer la demande
 * dans le volume partagé ; le service empaqueteur (app:environnement:empaqueteur), sans secrets ni accès
 * à la base, la traite. Sans ce réglage, la plateforme empaquette elle-même, comme avant.
 */
final readonly class EnvironmentBuildQueue
{
    public const string DIRECTORY = '.demandes';
    public const string BUILDER = 'empaqueteur';

    /** Les seules commandes qu'une demande peut lancer. */
    public const array COMMANDS = ['app:environnement:installer', 'app:environnement:synchroniser'];

    public function __construct(
        private InstalledEnvironments $installed,
        #[Autowire(env: 'ENVIRONMENTS_BUILDER')]
        private string $builder = '',
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /** Les empaquetages sont-ils confiés au service empaqueteur ? */
    public function isEnabled(): bool
    {
        return self::BUILDER === $this->builder;
    }

    /** @param list<string> $arguments */
    public function push(string $command, array $arguments): void
    {
        if (!\in_array($command, self::COMMANDS, true)) {
            throw new \InvalidArgumentException(sprintf('Commande « %s » refusée.', $command));
        }
        $this->filesystem->mkdir($this->directory());
        // Nom trié par date : les demandes se traitent dans l'ordre où elles arrivent.
        $file = sprintf('%s/%s-%s.json', $this->directory(), (new \DateTimeImmutable())->format('YmdHisu'), bin2hex(random_bytes(4)));
        $this->filesystem->dumpFile($file, json_encode(['command' => $command, 'arguments' => $arguments], \JSON_THROW_ON_ERROR));
    }

    /**
     * La plus ancienne demande, retirée de la file. Une demande illisible, ou pour une autre commande, est jetée.
     *
     * @return array{command: string, arguments: list<string>}|null
     */
    public function pop(): ?array
    {
        foreach (glob($this->directory().'/*.json') ?: [] as $file) {
            $data = json_decode((string) @file_get_contents($file), true);
            $this->filesystem->remove($file);
            if (\is_array($data) && \in_array($data['command'] ?? null, self::COMMANDS, true)
                && \is_array($data['arguments'] ?? null) && array_is_list($data['arguments'])
                && [] === array_filter($data['arguments'], static fn ($argument) => !\is_string($argument))) {
                return ['command' => $data['command'], 'arguments' => $data['arguments']];
            }
        }

        return null;
    }

    private function directory(): string
    {
        return $this->installed->directory().'/'.self::DIRECTORY;
    }
}
