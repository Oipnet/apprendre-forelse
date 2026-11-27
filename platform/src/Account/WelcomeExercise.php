<?php

namespace App\Account;

use App\Api\ExerciseUrls;
use App\Content\ContentRepository;
use App\Content\TrackVisibility;
use App\Entity\User;
use App\Security\TrackAccessChecker;

/**
 * Où arrive un nouveau compte qui n'avait pas de page où revenir : l'exercice d'accueil (`welcome: true` dans
 * exercise.yaml), court et guidé pas à pas, pour réussir quelque chose dès la première minute. Sur l'accueil,
 * les inscrits ne savaient pas par où commencer (11 comptes à 0 XP en novembre 2026).
 */
final readonly class WelcomeExercise
{
    public function __construct(
        private TrackVisibility $visibility,
        private ContentRepository $content,
        private TrackAccessChecker $access,
        private ExerciseUrls $urls,
    ) {
    }

    /**
     * L'adresse de l'exercice d'accueil, dans la langue de la page, si ce compte le voit et peut l'ouvrir (une
     * cohorte qui ne propose pas son parcours ne l'a pas) ; null sinon, et l'inscription mène à l'accueil.
     *
     * `$resume` : l'exercice d'où l'on s'est inscrit. La démo passe avant lui (presque toute inscription suit la
     * lecture d'un exercice), puis propose de le reprendre (`?reprendre=`, voir PlaygroundConfigFactory).
     */
    public function urlFor(User $user, ?string $resume = null): ?string
    {
        foreach ($this->visibility->tracksHere() as $track) {
            // Un parcours non traduit garde ses exercices en français : ils n'ont pas d'adresse dans cette langue.
            if ($track->locale !== $this->visibility->locale()) {
                continue;
            }
            foreach ($this->content->exercisesOf($track) as $exercise) {
                if ($exercise->welcome && $this->access->canAccessExercise($user, $exercise)) {
                    $url = $this->urls->generate('app_exercise', $exercise);

                    return null === $resume || $resume === $url ? $url : $url.'?'.http_build_query(['reprendre' => $resume]);
                }
            }
        }

        return null;
    }
}
