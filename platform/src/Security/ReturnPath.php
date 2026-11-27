<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Où ramener après une connexion ou une inscription. Les liens « Connexion » et « Créer un compte » n'emportent plus
 * la page d'où l'on vient (`?suite=`) : chaque page du site en fabriquait une adresse de connexion à part, que les
 * moteurs relevaient (« bloquée par robots.txt », « exclue par noindex ») et qu'il fallait cacher derrière des
 * nofollow. La page d'origine vient maintenant du Referer, que le navigateur envoie pour un lien interne
 * (Referrer-Policy strict-origin-when-cross-origin, voir OriginIsolationListener). Un robot n'en envoie pas : il ne
 * voit qu'une adresse /connexion et une adresse /inscription.
 *
 * `?suite=` reste lu, pour les liens déjà donnés et ceux qui mènent ailleurs que la page courante (« Acheter »).
 * Sans Referer (réglage du navigateur), la connexion mène à l'accueil, l'inscription à l'exercice d'accueil.
 */
final readonly class ReturnPath
{
    use TargetPathTrait;

    /** Les pages d'où l'on ne revient pas : la connexion elle-même, et ses voisines. */
    private const array AUTH_ROUTES = [
        'app_home',
        'app_login',
        'app_logout',
        'app_register',
        'app_forgot_password_request',
        'app_check_email',
        'app_reset_password',
        'app_oauth_start',
        'app_oauth_callback',
        'app_oauth_register',
    ];

    /**
     * Les pages où l'inscription ramène : un exercice. Ailleurs (un article, l'accueil d'un parcours), le nouveau
     * compte commence par l'exercice d'accueil, guidé (voir WelcomeExercise).
     */
    private const array EXERCISE_ROUTES = ['app_exercise', 'app_exercise_pratique'];

    public function __construct(private UrlMatcherInterface $matcher)
    {
    }

    /**
     * Pour la page de connexion : où la connexion ramène (`login` : `?suite=`, la page protégée, sinon toute page du
     * site d'où l'on vient), et où ramène son lien « Pas de compte ? » (`register` : la même, sauf si elle vient du
     * Referer sans être un exercice : l'inscrit commence alors par l'exercice d'accueil).
     *
     * @return array{login: ?string, register: ?string}
     */
    public function forLoginPage(Request $request): array
    {
        $explicit = $this->explicit($request);

        return [
            'login' => $explicit ?? $this->fromReferer($request, null),
            'register' => $explicit ?? $this->fromReferer($request, self::EXERCISE_ROUTES),
        ];
    }

    /** Pour l'inscription : `?suite=`, la page protégée, sinon l'exercice d'où l'on vient. */
    public function forRegistration(Request $request): ?string
    {
        return $this->explicit($request) ?? $this->fromReferer($request, self::EXERCISE_ROUTES);
    }

    /**
     * La page demandée en clair : `?suite=`, sinon celle qu'une page protégée (/compte, une fiche de cours, un achat)
     * a gardée en session avant de renvoyer ici. Elle passe avant le Referer, qui reste la page qui y menait après la
     * redirection. Elle est retirée de la session et voyage dans le formulaire : abandonnée, elle ne ramènerait pas
     * plus tard une connexion lancée d'ailleurs. Pas de session ouverte pour la lire.
     */
    private function explicit(Request $request): ?string
    {
        $suite = SafeRedirect::localPath($request->query->getString('suite') ?: null);
        if (null !== $suite || !$request->hasPreviousSession()) {
            return $suite;
        }

        $session = $request->getSession();
        $target = $this->getTargetPath($session, 'main');
        if (null === $target) {
            return null;
        }
        $this->removeTargetPath($session, 'main');

        // Le pare-feu garde l'adresse complète, les contrôleurs un chemin.
        $origin = $request->getSchemeAndHttpHost();
        $path = null === SafeRedirect::sameOrigin($target, $origin) ? $target : substr($target, \strlen($origin));

        return SafeRedirect::localPath($path);
    }

    /** @param list<string>|null $routes */
    private function fromReferer(Request $request, ?array $routes): ?string
    {
        $referer = SafeRedirect::sameOrigin($request->headers->get('referer'), $request->getSchemeAndHttpHost());
        if (null === $referer) {
            return null;
        }

        $path = substr($referer, \strlen($request->getSchemeAndHttpHost()));
        $path = SafeRedirect::localPath(explode('#', $path, 2)[0]);
        if (null === $path) {
            return null;
        }

        $route = $this->match($path)['_canonical_route'] ?? null;
        if (!\is_string($route) || \in_array($route, self::AUTH_ROUTES, true)) {
            return null;
        }

        return null === $routes || \in_array($route, $routes, true) ? $path : null;
    }

    /**
     * Les paramètres de route du chemin s'il mène à un exercice (trackId absent pour la Pratique, exerciseId,
     * _locale), sinon null. L'inscription depuis un exercice passe d'abord par l'exercice d'accueil, qui propose
     * ensuite de le reprendre (voir WelcomeExercise).
     *
     * @return array{trackId?: string, exerciseId: string, _locale?: string}|null
     */
    public function exercise(?string $path): ?array
    {
        $attributes = null === SafeRedirect::localPath($path) ? [] : $this->match((string) $path);
        if (!\in_array($attributes['_canonical_route'] ?? null, self::EXERCISE_ROUTES, true) || !\is_string($attributes['exerciseId'] ?? null)) {
            return null;
        }

        return array_filter([
            'trackId' => $attributes['trackId'] ?? null,
            'exerciseId' => $attributes['exerciseId'],
            '_locale' => $attributes['_locale'] ?? null,
        ], \is_string(...));
    }

    /**
     * Les attributs de la route du chemin (sans sa query string), avec `_canonical_route` toujours renseignée ; vide
     * s'il ne mène nulle part.
     *
     * @return array<string, mixed>
     */
    private function match(string $path): array
    {
        // Une page où l'on revient s'ouvre en GET, même quand la question se pose pendant l'envoi d'un formulaire.
        $context = $this->matcher->getContext();
        $method = $context->getMethod();
        $context->setMethod('GET');
        try {
            $attributes = $this->matcher->match(explode('?', $path, 2)[0]);
        } catch (ExceptionInterface) {
            return [];
        } finally {
            $context->setMethod($method);
        }
        $attributes['_canonical_route'] ??= $attributes['_route'] ?? null;

        return $attributes;
    }
}
