<?php

namespace App\Service;

use App\Api\FeedbackInput;
use App\Content\Exercise;
use App\Entity\Feedback;
use App\Entity\FeedbackKind;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class FeedbackService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function record(User $user, Exercise $exercise, FeedbackInput $input): Feedback
    {
        $feedback = new Feedback(
            $user,
            $exercise->trackId,
            $exercise->id,
            FeedbackKind::from($input->kind),
            trim($input->message),
            min($input->hintsUsed, \count($exercise->hints)),
            $input->completed,
        );
        $this->entityManager->persist($feedback);
        $this->entityManager->flush();

        return $feedback;
    }
}
