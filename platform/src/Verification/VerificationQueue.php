<?php

namespace App\Verification;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Le dossier partagé entre le worker, qui a la base, et le service « verificateur », qui n'a ni base, ni réseau, ni
 * secrets : les demandes (le code d'une réussite) y descendent, les verdicts y remontent. Un fichier par réussite,
 * nommé par l'identifiant de sa progression : une demande renvoyée remplace la précédente.
 *
 * Dossiers en 0700, créés par le worker (prepare()) : le code rejoué tourne sous un autre utilisateur
 * (VERIFICATION_SANDBOX_UID), qui ne doit ni lire le code des autres, ni écrire un verdict. Les fichiers, eux, sont en
 * 0644 : le verificateur (root, sans le droit de changer leur propriétaire) écrit des verdicts que le worker doit lire,
 * et le dossier fermé suffit à les cacher.
 *
 * VERIFICATIONS_DIR vide : la vérification est éteinte, les réussites restent « en attente » (et comptent).
 */
final readonly class VerificationQueue
{
    private const string REQUESTS = 'demandes';
    private const string RESULTS = 'resultats';

    public function __construct(
        #[Autowire(env: 'VERIFICATIONS_DIR')]
        private string $directory = '',
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== $this->directory;
    }

    /** Crée les deux dossiers, s'ils manquent, sous l'utilisateur courant : celui du worker, qui doit lire les verdicts. */
    public function prepare(): void
    {
        foreach ([self::REQUESTS, self::RESULTS] as $kind) {
            if (!is_dir($this->directory.'/'.$kind)) {
                $this->filesystem->mkdir($this->directory.'/'.$kind, 0o700);
            }
        }
    }

    public function push(VerificationRequest $request): void
    {
        $this->write(self::REQUESTS, $request->progressId, $request->toArray());
    }

    /** La plus ancienne demande, retirée du dossier. Une demande illisible est jetée. */
    public function pop(): ?VerificationRequest
    {
        foreach ($this->files(self::REQUESTS) as $file) {
            $data = $this->take($file);
            $request = null === $data ? null : VerificationRequest::fromArray($data);
            if (null !== $request) {
                return $request;
            }
        }

        return null;
    }

    public function pushResult(VerificationResult $result): void
    {
        $this->write(self::RESULTS, $result->progressId, $result->toArray());
    }

    /**
     * Les verdicts rendus depuis le dernier relevé, retirés du dossier.
     *
     * @return list<VerificationResult>
     */
    public function popResults(): array
    {
        $results = [];
        foreach ($this->files(self::RESULTS) as $file) {
            $data = $this->take($file);
            if (null !== $data && null !== $result = VerificationResult::fromArray($data)) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /** @param array<string, mixed> $data */
    private function write(string $kind, int $progressId, array $data): void
    {
        if (!$this->isEnabled()) {
            throw new \LogicException('Vérification éteinte : VERIFICATIONS_DIR est vide.');
        }
        $directory = $this->directory.'/'.$kind;
        if (!is_dir($directory)) {
            $this->filesystem->mkdir($directory, 0o700);
        }
        $file = sprintf('%s/%d.json', $directory, $progressId);
        // Écrit à côté puis renommé : le lecteur ne voit jamais un fichier à moitié écrit.
        $temporary = sprintf('%s/.%d.%s.tmp', $directory, $progressId, bin2hex(random_bytes(4)));
        file_put_contents($temporary, json_encode($data, \JSON_THROW_ON_ERROR));
        chmod($temporary, 0o644);
        rename($temporary, $file);
    }

    /** @return list<string> les fichiers, du plus ancien au plus récent */
    private function files(string $kind): array
    {
        if (!$this->isEnabled()) {
            return [];
        }
        $files = glob($this->directory.'/'.$kind.'/*.json') ?: [];
        usort($files, static fn (string $a, string $b) => (@filemtime($a) ?: 0) <=> (@filemtime($b) ?: 0) ?: strcmp($a, $b));

        return $files;
    }

    /** @return array<string, mixed>|null le contenu, le fichier supprimé */
    private function take(string $file): ?array
    {
        $content = @file_get_contents($file);
        $this->filesystem->remove($file);
        $data = false === $content ? null : json_decode($content, true);

        return \is_array($data) ? $data : null;
    }
}
