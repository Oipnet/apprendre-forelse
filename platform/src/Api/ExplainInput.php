<?php

namespace App\Api;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Corps de POST /api/mentor/{track}/{exercise}/explain : l'erreur rencontrée, et le code du moment. */
final readonly class ExplainInput
{
    /**
     * @param array<string, string> $files
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 20000)]
        public string $error,
        #[Assert\Choice(choices: ['preview', 'tests', 'console'])]
        public string $source = 'preview',
        #[Assert\Count(max: 50)]
        #[Assert\All([new Assert\Type('string')])]
        public array $files = [],
    ) {
    }

    #[Assert\Callback]
    public function checkSize(ExecutionContextInterface $context): void
    {
        MentorInputSize::check($this->files, $context, $this->error);
    }
}
