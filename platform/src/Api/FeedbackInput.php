<?php

namespace App\Api;

use App\Entity\FeedbackKind;
use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /api/feedback/{track}/{exercise}. */
final readonly class FeedbackInput
{
    public function __construct(
        #[Assert\Choice(callback: [FeedbackKind::class, 'values'], message: 'Type de retour inconnu.')]
        public string $kind,
        #[Assert\NotBlank(message: 'Dites-nous en un peu plus.', normalizer: 'trim')]
        #[Assert\Length(max: 2000, maxMessage: 'Au plus {{ limit }} caractères.')]
        public string $message,
        #[Assert\Range(min: 0, max: 20)]
        public int $hintsUsed = 0,
        public bool $completed = false,
    ) {
    }
}
