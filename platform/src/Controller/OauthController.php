<?php

namespace App\Controller;

use App\Account\Oauth\ExternalProfile;
use App\Account\Oauth\ExternalSignIn;
use App\Account\Oauth\OauthException;
use App\Account\Oauth\OauthProvider;
use App\Account\Oauth\OauthProviders;
use App\Account\Oauth\RecentSignIn;
use App\Account\Oauth\SignInOutcome;
use App\Account\RegistrationThrottle;
use App\Entity\User;
use App\Form\ExternalRegistrationFormType;
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
 * Connexion avec un fournisseur (GitHub, Google, LinkedIn) : départ vers lui, retour, fin d'inscription d'un nouvel
 * apprenant, et lien depuis le compte. Les règles (quel compte, lier ou non) sont dans ExternalSignIn, les échanges
 * avec chaque fournisseur dans son client ; ici, la session et les redirections.
 *
 * Un fournisseur inconnu ou sans identifiants répond 404 partout.
 */
final class OauthController extends AbstractController
{
    use TargetPathTrait;

    /** Ce que le départ confie au retour : le fournisseur, le jeton anti-falsification, l'intention, où revenir. */
    private const string FLOW = 'oauth.flow';
    /** Le profil d'une inscription à finaliser (voir register()). */
    private const string PENDING = 'oauth.pending';
    /** Se connecter ; lier le fournisseur au compte connecté ; confirmer son identité (compte sans mot de passe). */
    private const array MODES = ['connexion', 'lier', 'confirmer'];
    /** Les fournisseurs connus : la route ne capture pas d'autres adresses (/connexion/…, /inscription/…). */
    private const string PROVIDERS = 'github|google|linkedin';

    public function __construct(
        private readonly OauthProviders $providers,
        private readonly ExternalSignIn $signIn,
        private readonly RecentSignIn $recent,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/connexion/{fournisseur}', name: 'app_oauth_start', requirements: ['fournisseur' => self::PROVIDERS], methods: ['GET'])]
    public function start(Request $request, string $fournisseur): Response
    {
        $provider = $this->provider($fournisseur);
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
        $request->getSession()->set(self::FLOW, [
            'provider' => $provider->name(),
            'state' => $state,
            'mode' => $mode,
            'suite' => SafeRedirect::localPath(\is_string($suite) ? $suite : null),
        ]);

        return $this->redirect($provider->authorizationUrl($state, $this->callbackUrl($provider)));
    }

    #[Route('/connexion/{fournisseur}/retour', name: 'app_oauth_callback', requirements: ['fournisseur' => self::PROVIDERS], methods: ['GET'])]
    public function callback(Request $request, Security $security, string $fournisseur): Response
    {
        $provider = $this->provider($fournisseur);
        $label = $provider->label();
        $session = $request->getSession();
        $flow = $session->get(self::FLOW);
        $session->remove(self::FLOW);
        $state = $request->query->getString('state');
        if (!\is_array($flow) || ($flow['provider'] ?? null) !== $provider->name() || !\is_string($flow['state'] ?? null) || '' === $state || !hash_equals($flow['state'], $state)) {
            return $this->fail(sprintf('La connexion avec %s a expiré. Recommencez.', $label));
        }
        if ($request->query->has('error') || '' === $request->query->getString('code')) {
            // L'apprenant a refusé chez le fournisseur (error=access_denied).
            return $this->fail(sprintf('Connexion avec %s annulée.', $label));
        }

        try {
            $profile = $provider->fetchProfile($request->query->getString('code'), $this->callbackUrl($provider));
        } catch (OauthException $e) {
            $this->logger->warning('Connexion {provider} en échec : {message}', ['provider' => $label, 'message' => $e->getMessage()]);

            return $this->fail(sprintf('%s n\'a pas répondu comme prévu. Réessayez dans quelques minutes.', $label));
        }

        $suite = \is_string($flow['suite'] ?? null) ? $flow['suite'] : null;
        $mode = $flow['mode'] ?? null;
        $current = $this->getUser();
        if ('connexion' !== $mode) {
            if (!$current instanceof User) {
                return $this->redirectToRoute('app_login');
            }

            return 'confirmer' === $mode ? $this->afterConfirm($profile, $current, $label, $suite) : $this->afterLink($profile, $current, $label, $suite);
        }
        if ($current) {
            return $this->redirectToRoute('app_home');
        }

        $result = $this->signIn->resolve($profile);

        return match ($result->outcome) {
            SignInOutcome::SignedIn => $this->logIn($security, $result->user ?? throw new \LogicException(), $suite, sprintf('Connecté avec %s.', $label)),
            SignInOutcome::RegistrationNeeded => $this->startRegistration($session, $profile, $suite),
            SignInOutcome::UnverifiedAccount => $this->fail(sprintf('Un compte existe déjà avec l\'adresse de votre compte %1$s, mais elle n\'a jamais été confirmée : par sécurité, il n\'est pas rattaché d\'office. Connectez-vous avec votre mot de passe (ou « Mot de passe oublié ? »), puis liez %1$s depuis votre compte.', $label)),
            SignInOutcome::OtherProviderAccount => $this->fail(sprintf('Le compte qui utilise l\'adresse de votre compte %1$s est déjà lié à un autre compte %1$s. Connectez-vous avec ce compte %1$s, ou avec votre mot de passe.', $label)),
            SignInOutcome::NoVerifiedEmail => $this->fail(sprintf('Votre compte %s n\'a aucune adresse email vérifiée. Vérifiez-en une chez %1$s, ou créez un compte avec votre adresse et un mot de passe.', $label)),
            default => throw new \LogicException(sprintf('Issue inattendue : %s.', $result->outcome->name)),
        };
    }

    /** Nouvel apprenant venu d'un fournisseur : il choisit son pseudo et, s'il en a un, son code d'invitation. */
    #[Route('/inscription/{fournisseur}', name: 'app_oauth_register', requirements: ['fournisseur' => self::PROVIDERS], methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        Security $security,
        RegistrationThrottle $throttle,
        UserRepository $users,
        #[Autowire(env: 'bool:REGISTRATION_INVITE_ONLY')] bool $inviteOnly,
        string $fournisseur,
    ): Response {
        $provider = $this->provider($fournisseur);
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }
        $session = $request->getSession();
        $pending = $session->get(self::PENDING);
        if (!\is_array($pending) || !($pending['profile'] ?? null) instanceof ExternalProfile || $pending['profile']->provider !== $provider->name()) {
            return $this->redirectToRoute('app_login');
        }
        $profile = $pending['profile'];

        $form = $this->createForm(ExternalRegistrationFormType::class, ['displayName' => $profile->suggestedDisplayName(), 'invitationCode' => null], ['invite_only' => $inviteOnly]);
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
                return $this->fail(sprintf('Un compte vient d\'être créé avec cette adresse. Reconnectez-vous avec %s.', $provider->label()));
            }
            /** @var array{displayName: string, invitationCode: string|null} $data */
            $data = $form->getData();
            $user = $this->signIn->register($profile, $data['displayName'], $data['invitationCode']);
            $suite = \is_string($pending['suite'] ?? null) ? $pending['suite'] : null;

            return $this->logIn($security, $user, $suite, sprintf('Bienvenue, %s ! Votre compte est créé : votre progression est désormais sauvegardée.', $user->getDisplayName()));
        }

        return $this->render('security/oauth_register.html.twig', [
            'form' => $form,
            'profile' => $profile,
            'provider' => $provider,
            'inviteOnly' => $inviteOnly,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** Un fournisseur retiré de la configuration se délie encore : ce n'est que la ligne en base. */
    #[Route('/compte/{fournisseur}/delier', name: 'app_account_oauth_unlink', requirements: ['fournisseur' => self::PROVIDERS], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function unlink(Request $request, string $fournisseur): Response
    {
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        $user = $this->getUser();
        \assert($user instanceof User);
        $label = $this->providers->label($fournisseur);
        if ($this->signIn->unlink($user, $fournisseur)) {
            $this->addFlash('success', sprintf('%s n\'est plus lié à votre compte.', $label));
        } else {
            $this->addFlash('error', sprintf('Choisissez d\'abord un mot de passe (« Mot de passe oublié ? » sur la page de connexion) : sans %s ni mot de passe, vous ne pourriez plus vous connecter.', $label));
        }

        return $this->redirectToRoute('app_account', ['_fragment' => 'profil'], Response::HTTP_SEE_OTHER);
    }

    /** Retour du fournisseur pour le compte connecté, qui demande à le lier. */
    private function afterLink(ExternalProfile $profile, User $current, string $label, ?string $suite): Response
    {
        $result = $this->signIn->resolve($profile, $current);
        if (SignInOutcome::Linked === $result->outcome) {
            $this->recent->mark($current);
            $this->addFlash('success', sprintf('Votre compte %s %s est lié : vous pourrez vous connecter avec.', $label, $profile->username));
        } else {
            $this->addFlash('error', SignInOutcome::LinkedElsewhere === $result->outcome
                ? sprintf('Ce compte %s est lié à un autre compte de la plateforme.', $label)
                : sprintf('Votre compte est déjà lié à un autre compte %1$s : déliez-le d\'abord.', $label));
        }

        return $this->redirect($suite ?? $this->generateUrl('app_account', ['_fragment' => 'profil']));
    }

    /** Retour du fournisseur pour le compte connecté, qui confirme être son titulaire (compte sans mot de passe). */
    private function afterConfirm(ExternalProfile $profile, User $current, string $label, ?string $suite): Response
    {
        if ($this->signIn->confirms($profile, $current)) {
            $this->recent->mark($current);
            $this->addFlash('success', sprintf('Identité confirmée avec %s : vous avez cinq minutes.', $label));
        } else {
            $this->addFlash('error', sprintf('Ce compte %s n\'est pas celui qui est lié au vôtre : identité non confirmée.', $label));
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

    private function startRegistration(SessionInterface $session, ExternalProfile $profile, ?string $suite): Response
    {
        $session->set(self::PENDING, ['profile' => $profile, 'suite' => $suite]);

        return $this->redirectToRoute('app_oauth_register', ['fournisseur' => $profile->provider]);
    }

    private function fail(string $message): Response
    {
        $this->addFlash('error', $message);

        return $this->redirectToRoute($this->getUser() ? 'app_account' : 'app_login');
    }

    private function callbackUrl(OauthProvider $provider): string
    {
        return $this->generateUrl('app_oauth_callback', ['fournisseur' => $provider->name()], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function provider(string $name): OauthProvider
    {
        return $this->providers->get($name) ?? throw $this->createNotFoundException(sprintf('Connexion avec « %s » non configurée.', $name));
    }
}
