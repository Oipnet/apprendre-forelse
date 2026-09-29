<?php

namespace App\Account\Oauth;

use App\Entity\User;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Un compte sans mot de passe confirme son identité en repassant par un fournisseur qui lui est lié : changer
 * d'adresse ou supprimer le compte demande une connexion de moins de cinq minutes, dans cette session.
 */
final readonly class RecentSignIn
{
    public const int WINDOW_SECONDS = 300;
    private const string KEY = 'oauth.signed_in_at';

    public function __construct(
        private RequestStack $requests,
        private ClockInterface $clock,
    ) {
    }

    public function mark(User $user): void
    {
        $this->requests->getSession()->set(self::KEY, ['user' => $user->getId(), 'at' => $this->clock->now()->getTimestamp()]);
    }

    public function isRecent(User $user): bool
    {
        $mark = $this->requests->getSession()->get(self::KEY);

        return \is_array($mark) && ($mark['user'] ?? null) === $user->getId()
            && \is_int($mark['at'] ?? null) && $this->clock->now()->getTimestamp() - $mark['at'] <= self::WINDOW_SECONDS;
    }
}
