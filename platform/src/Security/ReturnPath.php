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

    /** Pour la connexion : `?suite=`, sinon toute page du site d'où l'on vient. */
    public function forLogin(Request $request): ?string
    {
        return $this->resolve($request, null);
    }

    /** Pour l'inscription : `?suite=`, sinon l'exercice d'où l'on vient. */
    public function forRegistration(Request $request): ?string
    {
        return $this->resolve($request, self::EXERCISE_ROUTES);
    }

    /** @param list<string>|null $routes les pages d'origine retenues ; null : toutes, sauf celles de la connexion */
    private function resolve(Request $request, ?array $routes): ?string
    {
        $suite = SafeRedirect::localPath($request->query->getString('suite') ?: null);
        if (null !== $suite) {
            return $suite;
        }

        // Une page protégée a renvoyé ici (/compte, par exemple) : le pare-feu l'a gardée, elle passe avant la page
        // qui y menait, que le navigateur annonce toujours comme Referer après la redirection.
        if ($request->hasPreviousSession() && null !== $this->getTargetPath($request->getSession(), 'main')) {
            return null;
        }

        return $this->fromReferer($request, $routes);
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

        try {
            $attributes = $this->matcher->match(rawurldecode((string) parse_url($path, \PHP_URL_PATH)));
        } catch (ExceptionInterface) {
            return null;
        }
        $route = $attributes['_canonical_route'] ?? $attributes['_route'] ?? null;
        if (!\is_string($route) || \in_array($route, self::AUTH_ROUTES, true)) {
            return null;
        }

        return null === $routes || \in_array($route, $routes, true) ? $path : null;
    }
}
