<?php

namespace App\Controller;

use App\Account\Github\GithubClient;
use App\Account\Github\GithubException;
use App\Account\Github\GithubProfile;
use App\Account\Github\GithubSignIn;
use App\Account\Github\GithubSignInOutcome;
use App\Account\Github\RecentSignIn;
use App\Account\RegistrationThrottle;
use App\Entity\User;
use App\Form\GithubRegistrationFormType;
use App\Repository\UserRepository;
use App\Security\SafeRedirect;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Connexion avec GitHub : départ vers GitHub, retour, fin d'inscription d'un nouvel apprenant, et lien depuis le
 * compte. Les règles (quel compte, lier ou non) sont dans GithubSignIn ; ici, la session et les redirections.
 */
final class GithubController extends AbstractController
{
    use TargetPathTrait;

    /** Ce que le départ confie au retour : le jeton anti-falsification, l'intention, et où revenir ensuite. */
    private const string FLOW = 'github.flow';
    /** Le profil GitHub d'une inscription à finaliser (voir register()). */
    private const string PENDING = 'github.pending';
    /** Se connecter ; lier GitHub au compte connecté ; confirmer son identité (compte sans mot de passe). */
    private const array MODES = ['connexion', 'lier', 'confirmer'];

    public function __construct(
        private readonly GithubClient $github,
        private readonly GithubSignIn $signIn,
        private readonly RecentSignIn $recent,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/connexion/github', name: 'app_github_start', methods: ['GET'])]
    public function start(Request $request): Response
    {
        $this->ensureEnabled();
        $mode = $request->query->getString('mode', 'connexion');
        if (!\in_array($mode, self::MODES, true)) {
            throw $this->createNotFoundException();
        }
        if ('connexion' === $mode && $this->getUser()) {
            return $this->redirectToRoute('app_home');
        }
        if ('connexion' !== $mode && !$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        $state = bin2hex(random_bytes(16));
        // Où revenir : la page demandée (?suite=), sinon celle qui a mené à la connexion (chemin cible du pare-feu).
        $suite = $request->query->getString('suite') ?: $this->getTargetPath($request->getSession(), 'main');
        $request->getSession()->set(self::FLOW, ['state' => $state, 'mode' => $mode, 'suite' => SafeRedirect::localPath(\is_string($suite) ? $suite : null)]);

        return $this->redirect($this->github->authorizationUrl($state, $this->callbackUrl()));
    }

    #[Route('/connexion/github/retour', name: 'app_github_callback', methods: ['GET'])]
    public function callback(Request $request, Security $security): Response
    {
        $this->ensureEnabled();
        $session = $request->getSession();
        $flow = $session->get(self::FLOW);
        $session->remove(self::FLOW);
        $state = $request->query->getString('state');
        if (!\is_array($flow) || !\is_string($flow['state'] ?? null) || '' === $state || !hash_equals($flow['state'], $state)) {
            return $this->fail('La connexion avec GitHub a expiré. Recommencez.');
        }
        if ($request->query->has('error') || '' === $request->query->getString('code')) {
            // L'apprenant a refusé chez GitHub (error=access_denied).
            return $this->fail('Connexion avec GitHub annulée.');
        }

        try {
            $profile = $this->github->fetchProfile($request->query->getString('code'), $this->callbackUrl());
        } catch (GithubException $e) {
            $this->logger->warning('Connexion GitHub en échec : {message}', ['message' => $e->getMessage()]);

            return $this->fail('GitHub n\'a pas répondu comme prévu. Réessayez dans quelques minutes.');
        }

        $suite = \is_string($flow['suite'] ?? null) ? $flow['suite'] : null;
        $mode = $flow['mode'] ?? null;
        $current = $this->getUser();
        if ('connexion' !== $mode) {
            return $current instanceof User ? $this->afterLink($profile, $current, 'confirmer' === $mode, $suite) : $this->redirectToRoute('app_login');
        }
        if ($current) {
            return $this->redirectToRoute('app_home');
        }

        $result = $this->signIn->resolve($profile);

        return match ($result->outcome) {
            GithubSignInOutcome::SignedIn => $this->logIn($security, $result->user ?? throw new \LogicException(), $suite, 'Connecté avec GitHub.'),
            GithubSignInOutcome::RegistrationNeeded => $this->startRegistration($session, $profile, $suite),
            GithubSignInOutcome::UnverifiedAccount => $this->fail('Un compte existe déjà avec l\'adresse de votre compte GitHub, mais elle n\'a jamais été confirmée : par sécurité, il n\'est pas rattaché d\'office. Connectez-vous avec votre mot de passe (ou « Mot de passe oublié ? »), puis liez GitHub depuis votre compte.'),
            GithubSignInOutcome::OtherGithubAccount => $this->fail('Le compte qui utilise l\'adresse de votre compte GitHub est déjà lié à un autre compte GitHub. Connectez-vous avec ce compte GitHub, ou avec votre mot de passe.'),
            GithubSignInOutcome::NoVerifiedEmail => $this->fail('Votre compte GitHub n\'a aucune adresse email vérifiée. Vérifiez-en une sur GitHub (Settings → Emails), ou créez un compte avec votre adresse et un mot de passe.'),
            default => throw new \LogicException(sprintf('Issue inattendue : %s.', $result->outcome->name)),
        };
    }

    /** Nouvel apprenant venu de GitHub : il choisit son pseudo et, s'il en a un, son code d'invitation. */
    #[Route('/inscription/github', name: 'app_github_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        Security $security,
        RegistrationThrottle $throttle,
        UserRepository $users,
        #[Autowire(env: 'bool:REGISTRATION_INVITE_ONLY')] bool $inviteOnly,
    ): Response {
        $this->ensureEnabled();
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }
        $session = $request->getSession();
        $pending = $session->get(self::PENDING);
        if (!\is_array($pending) || !($pending['profile'] ?? null) instanceof GithubProfile) {
            return $this->redirectToRoute('app_login');
        }
        $profile = $pending['profile'];

        $form = $this->createForm(GithubRegistrationFormType::class, ['displayName' => $profile->suggestedDisplayName(), 'invitationCode' => null], ['invite_only' => $inviteOnly]);
        $form->handleRequest($request);
        $ip = $request->getClientIp();
        $withCode = $form->isSubmitted() && '' !== trim((string) $form->get('invitationCode')->getData());
        if ($form->isSubmitted() && $throttle->isBlocked($ip)) {
            $form->addError(new FormError('Beaucoup d\'inscriptions refusées depuis votre connexion : réessayez dans une heure.'));
        } elseif ($withCode && $throttle->tooManyCodes($ip)) {
            $form->get('invitationCode')->addError(new FormError('Trop de codes d\'invitation essayés depuis votre connexion : réessayez dans une heure, ou demandez le lien d\'invitation à votre formateur.'));
        } elseif ($withCode && !$form->get('invitationCode')->isValid()) {
            $throttle->recordUnknownCode($ip);
        } elseif ($form->isSubmitted() && $form->isValid()) {
            $session->remove(self::PENDING);
            if (null !== $users->findOneByEmail((string) $profile->primaryEmail())) {
                // Inscrit entre-temps avec cette adresse (autre onglet) : on repasse par la connexion.
                return $this->fail('Un compte vient d\'être créé avec cette adresse. Reconnectez-vous avec GitHub.');
            }
            /** @var array{displayName: string, invitationCode: string|null} $data */
            $data = $form->getData();
            $user = $this->signIn->register($profile, $data['displayName'], $data['invitationCode']);
            $suite = \is_string($pending['suite'] ?? null) ? $pending['suite'] : null;

            return $this->logIn($security, $user, $suite, sprintf('Bienvenue, %s ! Votre compte est créé : votre progression est désormais sauvegardée.', $user->getDisplayName()));
        }

        return $this->render('security/github_register.html.twig', [
            'form' => $form,
            'profile' => $profile,
            'inviteOnly' => $inviteOnly,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/compte/github/delier', name: 'app_account_github_unlink', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function unlink(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        $user = $this->getUser();
        \assert($user instanceof User);
        if ($this->signIn->unlink($user)) {
            $this->addFlash('success', 'GitHub n\'est plus lié à votre compte.');
        } else {
            $this->addFlash('error', 'Choisissez d\'abord un mot de passe (« Mot de passe oublié ? » sur la page de connexion) : sans GitHub ni mot de passe, vous ne pourriez plus vous connecter.');
        }

        return $this->redirectToRoute('app_account', ['_fragment' => 'profil'], Response::HTTP_SEE_OTHER);
    }

    /** Retour de GitHub pour le compte connecté : le lier, ou confirmer que c'est bien son titulaire. */
    private function afterLink(GithubProfile $profile, User $current, bool $confirming, ?string $suite): Response
    {
        $result = $this->signIn->resolve($profile, $current);
        if (GithubSignInOutcome::Linked === $result->outcome) {
            $this->recent->mark($current);
            $this->addFlash('success', $confirming ? 'Identité confirmée avec GitHub : vous avez cinq minutes.' : sprintf('Votre compte GitHub %s est lié : vous pourrez vous connecter avec.', $profile->login));
        } else {
            $this->addFlash('error', GithubSignInOutcome::LinkedElsewhere === $result->outcome
                ? 'Ce compte GitHub est lié à un autre compte de la plateforme.'
                : 'Votre compte est déjà lié à un autre compte GitHub : déliez-le d\'abord.');
        }

        return $this->redirect($suite ?? $this->generateUrl('app_account', ['_fragment' => 'profil']));
    }

    private function logIn(Security $security, User $user, ?string $suite, string $message): Response
    {
        $security->login($user, 'form_login', 'main');
        $this->recent->mark($user);
        $this->addFlash('success', $message);

        return $this->redirect($suite ?? $this->generateUrl('app_home'));
    }

    private function startRegistration(SessionInterface $session, GithubProfile $profile, ?string $suite): Response
    {
        $session->set(self::PENDING, ['profile' => $profile, 'suite' => $suite]);

        return $this->redirectToRoute('app_github_register');
    }

    private function fail(string $message): Response
    {
        $this->addFlash('error', $message);

        return $this->redirectToRoute($this->getUser() ? 'app_account' : 'app_login');
    }

    private function callbackUrl(): string
    {
        return $this->generateUrl('app_github_callback', referenceType: UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function ensureEnabled(): void
    {
        if (!$this->github->isEnabled()) {
            throw $this->createNotFoundException('Connexion avec GitHub non configurée.');
        }
    }
}
