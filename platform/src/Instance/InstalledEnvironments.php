<?php

namespace App\Instance;

use App\Content\ContentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Le dossier écrivable où l'instance installe ses propres environnements (INSTALLED_ENVIRONMENTS_DIR,
 * `/environnements` dans l'image), à côté de ceux que le moteur livre.
 *
 * Sans ce dossier, la fonctionnalité est simplement absente : une instance qui n'en veut pas ne monte
 * rien et l'administration n'affiche pas la page.
 */
final class InstalledEnvironments
{
    /** L'état d'un environnement installé, déposé dans son dossier. */
    public const string STATE_FILE = '.forelse.json';

    /** Les archives construites, servies par EnvironmentArtifactController. */
    public const string ARTIFACTS = '.artefacts';

    /**
     * Les installations en cours ou échouées, le temps qu'elles aboutissent.
     *
     * Un environnement se nomme lui-même, dans son environment.yaml : tant que le dépôt n'est pas cloné,
     * on ne connaît pas son identifiant. Sans cette trace, un clone qui échoue ne laisserait rien à voir
     * à l'administrateur — l'écran resterait vide sans qu'on sache pourquoi.
     */
    public const string JOBS = '.installations';

    private readonly Filesystem $filesystem;

    public function __construct(
        #[Autowire(env: 'resolve:INSTALLED_ENVIRONMENTS_DIR')]
        private readonly string $directory,
    ) {
        $this->filesystem = new Filesystem();
    }

    /** L'instance peut-elle installer des environnements ? (Un dossier écrivable est monté.) */
    public function isEnabled(): bool
    {
        return '' !== $this->directory && is_dir($this->directory) && is_writable($this->directory);
    }

    /** Le dossier lui-même, même s'il n'est pas monté : les messages d'erreur le nomment. */
    public function directory(): string
    {
        return $this->directory;
    }

    public function artifactsDirectory(): string
    {
        return $this->directory.'/'.self::ARTIFACTS;
    }

    public function directoryOf(string $id): string
    {
        return $this->directory.'/'.self::id($id);
    }

    public function has(string $id): bool
    {
        return is_file($this->directoryOf($id).'/'.self::STATE_FILE);
    }

    /** @return array<string, InstalledEnvironment> par identifiant, dans l'ordre alphabétique */
    public function all(): array
    {
        $installed = [];
        foreach (glob($this->directory.'/*/'.self::STATE_FILE) ?: [] as $file) {
            $environment = $this->read(basename(\dirname($file)));
            if (null !== $environment) {
                $installed[$environment->id] = $environment;
            }
        }
        ksort($installed);

        return $installed;
    }

    public function read(string $id): ?InstalledEnvironment
    {
        $file = $this->directoryOf($id).'/'.self::STATE_FILE;
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return \is_array($data) ? InstalledEnvironment::fromArray($data + ['id' => $id]) : null;
    }

    public function write(InstalledEnvironment $environment): void
    {
        $this->filesystem->dumpFile(
            $this->directoryOf($environment->id).'/'.self::STATE_FILE,
            json_encode($environment->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n",
        );
    }

    /**
     * Les installations en cours ou échouées, de la plus récente à la plus ancienne.
     *
     * @return list<array{url: string, ref: string, state: string, message: string, startedAt: string, key: string}>
     */
    public function jobs(): array
    {
        $jobs = [];
        foreach (glob($this->directory.'/'.self::JOBS.'/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (\is_array($data)) {
                $jobs[] = [
                    'url' => (string) ($data['url'] ?? ''),
                    'ref' => (string) ($data['ref'] ?? ''),
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
    public function startJob(string $url, string $ref): string
    {
        $key = substr(sha1($url.'#'.$ref), 0, 16);
        $this->writeJob($key, [
            'url' => $url,
            'ref' => $ref,
            'state' => InstalledEnvironment::INSTALLING,
            'message' => 'Clonage du dépôt…',
            'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        return $key;
    }

    /** Une installation aboutie s'efface : l'environnement lui-même prend le relais dans la liste. */
    public function finishJob(string $url, string $ref, ?string $error = null): void
    {
        $key = substr(sha1($url.'#'.$ref), 0, 16);
        if (null === $error) {
            $this->forgetJob($key);

            return;
        }
        $job = $this->jobData($key) ?? ['url' => $url, 'ref' => $ref, 'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)];
        $this->writeJob($key, [...$job, 'state' => InstalledEnvironment::FAILED, 'message' => $error]);
    }

    public function forgetJob(string $key): void
    {
        if (1 === preg_match('/^[a-f0-9]{16}$/', $key)) {
            $this->filesystem->remove($this->directory.'/'.self::JOBS.'/'.$key.'.json');
        }
    }

    /** @return array<string, mixed>|null */
    private function jobData(string $key): ?array
    {
        $data = json_decode((string) @file_get_contents($this->directory.'/'.self::JOBS.'/'.$key.'.json'), true);

        return \is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $data */
    private function writeJob(string $key, array $data): void
    {
        $this->filesystem->dumpFile(
            $this->directory.'/'.self::JOBS.'/'.$key.'.json',
            json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n",
        );
    }

    /** Retire l'environnement et ses archives. Irréversible : le dossier cloné est supprimé. */
    public function remove(string $id): void
    {
        $id = self::id($id);
        $this->filesystem->remove([
            $this->directoryOf($id),
            $this->artifactsDirectory().'/'.$id.'.zip',
            $this->artifactsDirectory().'/'.$id.'.completion.json',
        ]);
    }

    /**
     * Un identifiant d'environnement est un nom de dossier, rien d'autre : ni chemin, ni remontée.
     * Il sert à composer des chemins de fichiers, donc il est vérifié plutôt que nettoyé.
     */
    public static function id(string $id): string
    {
        if (1 !== preg_match('/^[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?$/', $id)) {
            throw new ContentException(sprintf('Identifiant d\'environnement « %s » invalide : des minuscules, des chiffres et des tirets, 64 caractères au plus.', $id));
        }

        return $id;
    }
}
