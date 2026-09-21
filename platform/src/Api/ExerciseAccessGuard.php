<?php

namespace App\Api;

use App\Content\Exercise;
use App\Content\TrackVisibility;
use App\Entity\User;
use App\Security\TrackAccessChecker;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Le contrôle d'accès des API d'un exercice (contenu, progression, mentor) : 404 pour un parcours invisible,
 * 401 sans compte, 403 pour un chapitre fermé. Les pages HTML font la même chose avec la page publique de l'exercice.
 */
final readonly class ExerciseAccessGuard
{
    public function __construct(
        private TrackVisibility $visibility,
        private TrackAccessChecker $access,
    ) {
    }

    public function check(?User $user, Exercise $exercise): void
    {
        if (null !== $exercise->trackId && null === $this->visibility->find($exercise->trackId)) {
            throw new NotFoundHttpException();
        }
        if ($this->access->canAccessExercise($user, $exercise)) {
            return;
        }

        throw null === $user
            ? new HttpException(Response::HTTP_UNAUTHORIZED, 'Connectez-vous pour accéder à cet exercice.')
            : new HttpException(Response::HTTP_FORBIDDEN, 'Cet exercice fait partie du parcours complet : achetez le parcours pour y accéder.');
    }
}
