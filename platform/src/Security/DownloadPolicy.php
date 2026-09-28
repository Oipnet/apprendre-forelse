<?php

namespace App\Security;

use App\Content\ContentRepository;
use App\Content\Track;
use App\Content\TrackVisibility;
use App\Entity\User;

/**
 * Un fichier qu'un parcours déclare (clé « downloads » de track.yaml) est réservé à ceux qui ont accès à tout ce
 * parcours ; caché si le parcours est invisible pour le compte. Un fichier qu'aucun parcours ne déclare reste ouvert.
 */
final readonly class DownloadPolicy
{
    public function __construct(
        private ContentRepository $content,
        private TrackVisibility $visibility,
        private TrackAccessChecker $access,
    ) {
    }

    public function decide(?User $user, string $file): DownloadDecision
    {
        $tracks = $this->content->tracksOffering($file);
        if ([] === $tracks) {
            return DownloadDecision::Allowed;
        }
        $visible = array_filter($tracks, fn (Track $track) => null !== $this->visibility->find($track->id));
        if ([] === $visible) {
            return DownloadDecision::Hidden;
        }
        foreach ($visible as $track) {
            if ($this->access->hasFullAccess($user, $track)) {
                return DownloadDecision::Allowed;
            }
        }

        return DownloadDecision::Denied;
    }
}
