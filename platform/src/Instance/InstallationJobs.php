<?php

namespace App\Instance;

use App\Content\RepositoryUrl;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Le journal des installations d'environnements en cours ou échouées, dans le dossier des environnements
 * installés (voir InstalledEnvironments).
 */
final class InstallationJobs
{
    /**
     * Les installations en cours ou échouées, le temps qu'elles aboutissent.
     *
     * Un environnement se nomme lui-même, dans son environment.yaml : tant que le dépôt n'est pas cloné,
     * on ne connaît pas son identifiant. Sans cette trace, un clone qui échoue ne laisserait rien à voir
     * à l'administrateur — l'écran resterait vide sans qu'on sache pourquoi.
     */
    public const string DIRECTORY = '.installations';

    private readonly Filesystem $filesystem;

    public function __construct(
        private readonly InstalledEnvironments $installed,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * Les installations en cours ou échouées, de la plus récente à la plus ancienne.
     *
     * @return list<array{url: string, displayUrl: string, ref: string, dossier: string, state: string, message: string, startedAt: string, key: string}>
     */
    public function all(): array
    {
        $jobs = [];
        foreach (glob($this->installed->directory().'/'.self::DIRECTORY.'/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (\is_array($data)) {
                $jobs[] = [
                    'url' => (string) ($data['url'] ?? ''),
                    // Affichable : une adresse peut porter le jeton d'un dépôt privé.
                    'displayUrl' => RepositoryUrl::withoutCredentials((string) ($data['url'] ?? '')),
                    'ref' => (string) ($data['ref'] ?? ''),
                    'dossier' => (string) ($data['dossier'] ?? ''),
                    'state' => (string) ($data['state'] ?? InstalledEnvironment::FAILED),
                    'message' => (string) ($data['message'] ?? ''),
                    'startedAt' => (string) ($data['startedAt'] ?? ''),
                    'key' => basename($file, '.json'),
                ];
            }
        }
        usort($jobs, static fn (array $a, array $b) => $b['startedAt'] <=> $a['startedAt']);

        return $jobs;
    }

    /** Note qu'une installation commence, avant même de savoir quel environnement en sortira. */
    public function start(string $url, string $ref, string $dossier = ''): string
    {
        $key = self::key($url, $ref, $dossier);
        $this->write($key, [
            'url' => $url,
            'ref' => $ref,
            'dossier' => $dossier,
            'state' => InstalledEnvironment::INSTALLING,
            'message' => 'Clonage du dépôt…',
            'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        return $key;
    }

    /** Une installation aboutie s'efface : l'environnement lui-même prend le relais dans la liste. */
    public function finish(string $url, string $ref, string $dossier = '', ?string $error = null): void
    {
        $key = self::key($url, $ref, $dossier);
        if (null === $error) {
            $this->forget($key);

            return;
        }
        $job = $this->read($key) ?? ['url' => $url, 'ref' => $ref, 'dossier' => $dossier, 'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)];
        $this->write($key, [...$job, 'state' => InstalledEnvironment::FAILED, 'message' => $error]);
    }

    /** Deux environnements d'un même dépôt s'installent séparément : le dossier fait partie de la clé. */
    private static function key(string $url, string $ref, string $dossier): string
    {
        return substr(sha1($url.'#'.$ref.'#'.$dossier), 0, 16);
    }

    public function forget(string $key): void
    {
        if (1 === preg_match('/^[a-f0-9]{16}$/', $key)) {
            $this->filesystem->remove($this->installed->directory().'/'.self::DIRECTORY.'/'.$key.'.json');
        }
    }

    /** @return array<string, mixed>|null */
    private function read(string $key): ?array
    {
        $data = json_decode((string) @file_get_contents($this->installed->directory().'/'.self::DIRECTORY.'/'.$key.'.json'), true);

        return \is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $data */
    private function write(string $key, array $data): void
    {
        $this->filesystem->dumpFile(
            $this->installed->directory().'/'.self::DIRECTORY.'/'.$key.'.json',
            json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n",
        );
    }
}
