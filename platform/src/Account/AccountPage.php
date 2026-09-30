<?php

namespace App\Account;

use App\Content\ContentRepository;
use App\Account\Oauth\OauthProviders;
use App\Entity\User;
use App\Repository\ExternalIdentityRepository;
use App\Repository\PurchaseRepository;
use App\Repository\TrackAccessRepository;
use Psr\Clock\ClockInterface;

/** Ce que la page du compte affiche, hors formulaires : progression, accès, achats, comptes liés chez les fournisseurs (GitHub, Google, LinkedIn). */
final readonly class AccountPage
{
    public function __construct(
        private AccountProgress $progress,
        private TrackAccessRepository $accesses,
        private PurchaseRepository $purchases,
        private ContentRepository $content,
        private ClockInterface $clock,
        private OauthProviders $providers,
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
            // Les fournisseurs activés, et ceux qu'un compte garde liés même s'ils ont été retirés de la configuration.
            'oauthProviders' => $this->providers->enabled(),
            'identities' => $this->identities->findByUser($user),
        ];
    }
}
