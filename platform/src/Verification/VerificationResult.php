<?php

namespace App\Verification;

use App\Entity\VerificationStatus;

/** Le verdict du verificateur sur une réussite. */
final readonly class VerificationResult
{
    public function __construct(
        public int $progressId,
        public VerificationStatus $status,
        /** Ce qui a échoué, ou pourquoi rien n'a pu être rejoué ; null quand tout passe. */
        public ?string $detail = null,
    ) {
    }

    /** @return array{progressId: int, status: string, detail: ?string} */
    public function toArray(): array
    {
        return ['progressId' => $this->progressId, 'status' => $this->status->value, 'detail' => $this->detail];
    }

    /** @param array<mixed> $data null si le verdict est mal formé */
    public static function fromArray(array $data): ?self
    {
        $status = \is_string($data['status'] ?? null) ? VerificationStatus::tryFrom($data['status']) : null;
        // Un verdict ne remet jamais une réussite « en attente ».
        if (!\is_int($data['progressId'] ?? null) || null === $status || VerificationStatus::Pending === $status) {
            return null;
        }
        $detail = $data['detail'] ?? null;

        return new self($data['progressId'], $status, \is_string($detail) ? $detail : null);
    }
}
