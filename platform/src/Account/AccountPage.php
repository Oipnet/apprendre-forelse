<?php

namespace App\Account;

use App\Content\ContentRepository;
use App\Account\Github\GithubClient;
use App\Entity\ExternalIdentity;
use App\Entity\User;
use App\Repository\ExternalIdentityRepository;
use App\Repository\PurchaseRepository;
use App\Repository\TrackAccessRepository;
use Psr\Clock\ClockInterface;

/** Ce que la page du compte affiche, hors formulaires : progression, accès, achats, compte GitHub lié. */
final readonly class AccountPage
{
    public function __construct(
        private AccountProgress $progress,
        private TrackAccessRepository $accesses,
        private PurchaseRepository $purchases,
        private ContentRepository $content,
        private ClockInterface $clock,
        private GithubClient $github,
        private ExternalIdentityRepository $identities,
    ) {
    }

    /** @return array<string, mixed> */
    public function of(User $user): array
    {
        return [
            'progress' => $this->progress->tracks($user),
            'practiceCompleted' => $this->progress->practiceCompleted($user),
            'completedExercises' => $this->progress->completedExercises($user),
            'activeAccesses' => $this->progress->activeAccesses($user),
            'accesses' => $this->accesses->findByUser($user),
            'purchases' => $this->purchases->findByUser($user),
            'tracks' => $this->content->tracks(),
            'now' => $this->clock->now(),
            'githubEnabled' => $this->github->isEnabled(),
            'githubIdentity' => $this->identities->findOneByUser($user, ExternalIdentity::GITHUB),
        ];
    }
}
