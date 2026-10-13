<?php

namespace App\Verification;

/** Une réussite à rejouer : l'exercice, et le code soumis. */
final readonly class VerificationRequest
{
    /**
     * @param array<string, string> $files fichiers éditables, par chemin
     */
    public function __construct(
        public int $progressId,
        /** null : un exercice de Pratique */
        public ?string $trackId,
        public string $exerciseId,
        public array $files,
    ) {
    }

    /** @return array{progressId: int, trackId: ?string, exerciseId: string, files: array<string, string>} */
    public function toArray(): array
    {
        return ['progressId' => $this->progressId, 'trackId' => $this->trackId, 'exerciseId' => $this->exerciseId, 'files' => $this->files];
    }

    /** @param array<mixed> $data null si la demande est mal formée */
    public static function fromArray(array $data): ?self
    {
        $files = $data['files'] ?? null;
        if (!\is_int($data['progressId'] ?? null) || !\is_string($data['exerciseId'] ?? null)
            || !(null === ($data['trackId'] ?? null) || \is_string($data['trackId']))
            || !\is_array($files) || [] !== array_filter($files, static fn ($content, $path) => !\is_string($content) || !\is_string($path), \ARRAY_FILTER_USE_BOTH)) {
            return null;
        }

        /** @var array<string, string> $files */
        return new self($data['progressId'], $data['trackId'] ?? null, $data['exerciseId'], $files);
    }
}
