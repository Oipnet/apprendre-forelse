<?php

namespace App\Api;

use App\Ai\Mentor;
use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Entity\User;
use App\Security\SandboxOrigin;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Configuration du playground (attribut data-config de exercise/play.html.twig), pour un exercice de parcours
 * comme pour la Pratique. Contrat : playground/src/app/types.ts (PlaygroundConfig).
 */
final readonly class PlaygroundConfigFactory
{
    public function __construct(
        private ContentRepository $content,
        private ExerciseUrls $exerciseUrls,
        private UrlGeneratorInterface $urls,
        private SandboxOrigin $sandbox,
        private Mentor $mentor,
        private Packages $assets,
        private Security $security,
        /** Bêta fermée : l'inscription exige un code de cohorte, l'invité sans code est orienté vers la liste d'attente. */
        #[Autowire(env: 'bool:REGISTRATION_INVITE_ONLY')]
        private bool $inviteOnly,
    ) {
    }

    /** @return array<string, mixed> */
    public function create(Exercise $exercise): array
    {
        $user = $this->security->getUser();
        $track = null === $exercise->trackId ? null : $this->content->findTrack($exercise->trackId);
        $next = $this->content->next($exercise);

        return [
            'context' => null === $track ? 'practice' : 'track',
            'exerciseUrl' => $this->exerciseUrls->generate('api_exercise', $exercise),
            'sandboxUrl' => $this->sandbox->relayUrl(),
            // La marque, comme dans l'en-tête du site (la page n'a pas cet en-tête : le playground dessine sa barre).
            'logoUrl' => $this->assets->getUrl('img/logo.png'),
            // Le lien de retour de la barre : le parcours, ou la liste de la Pratique.
            'back' => null === $track
                ? ['title' => 'Pratique', 'url' => $this->urls->generate('app_practice')]
                : ['title' => $track->title, 'url' => $this->urls->generate('app_track', ['trackId' => $track->id])],
            'progress' => $user
                ? ['mode' => 'api', 'url' => $this->exerciseUrls->generate('api_progress_show', $exercise)]
                : ['mode' => 'local'],
            // Qui joue : affiché dans la barre du playground (la page n'a pas l'en-tête du site).
            'user' => $user instanceof User ? ['name' => $user->getDisplayName(), 'xp' => $user->getXp()] : null,
            'feedbackUrl' => $user ? $this->exerciseUrls->generate('api_feedback', $exercise) : null,
            // Le mentor (revue de code, erreurs expliquées) : comptes seulement, et si une clé d'API est fournie.
            'mentor' => $user && $this->mentor->disponible() ? [
                'reviewUrl' => $this->exerciseUrls->generate('api_mentor_review', $exercise),
                'explainUrl' => $this->exerciseUrls->generate('api_mentor_explain', $exercise),
            ] : null,
            'loginUrl' => $user ? null : $this->urls->generate('app_login'),
            'registerUrl' => $user ? null : $this->urls->generate('app_register', $next ? [
                'suite' => $this->exerciseUrls->generate('app_exercise', $next),
            ] : []),
            // Bêta fermée : l'invité sans code d'invitation est orienté vers la liste d'attente plutôt que vers un formulaire qu'il ne peut pas remplir.
            'waitlistUrl' => !$user && $this->inviteOnly ? $this->urls->generate('app_home').'#liste-attente' : null,
        ];
    }
}
