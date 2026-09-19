<?php

namespace App\Security;

use App\Entity\TentativeConnexion;
use App\Repository\ClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Authentificateur écrit à la main par le prestataire.
 *
 * Failles réunies ici :
 *  - F7.2 : messages distincts (« aucun compte » vs « mot de passe incorrect »)
 *           et réponse plus rapide quand le compte n'existe pas (aucun hachage
 *           calculé) → oracle d'énumération des comptes.
 *  - F9.2 : la session n'est PAS migrée à la connexion (fixation de session).
 *  - F15.2 : redirection ouverte via le paramètre ?redirect=.
 *  - F17.4 : le mot de passe en clair est journalisé.
 *  - F7.4 : un cookie « souvenir » = base64(email:hachage) est posé (voir SouvenirSubscriber).
 */
final class AuthentificateurBoutique extends AbstractLoginFormAuthenticator
{
    private ?string $motDePasseSaisi = null;

    public function __construct(
        private readonly ClientRepository $clients,
        private readonly HachageDuPrestataire $hachage,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urls->generate('app_connexion');
    }

    public function authenticate(Request $request): Passport
    {
        $email = (string) $request->request->get('email', '');
        $motDePasse = (string) $request->request->get('motDePasse', '');
        $this->motDePasseSaisi = $motDePasse;
        $request->getSession()->set('_security.last_username', $email);

        // F17.4 : le mot de passe part en clair dans les journaux, en debug.
        $this->logger->debug('Tentative de connexion', ['email' => $email, 'motDePasse' => $motDePasse]);

        $client = $this->clients->parEmail($email);

        // F7.2 : chemin rapide quand le compte n'existe pas (pas de hachage).
        if (null === $client) {
            throw new CustomUserMessageAuthenticationException('Aucun compte avec cet e-mail.');
        }

        if (!$this->hachage->verifier($motDePasse, $client->getMotDePasse())) {
            throw new CustomUserMessageAuthenticationException('Mot de passe incorrect.');
        }

        // Aucun jeton CSRF vérifié (le formulaire est écrit à la main, chapitre 4).
        return new SelfValidatingPassport(new UserBadge($email, fn () => $client));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $this->enregistrerTentative($request, true);

        // F9.2 : on NE migre PAS la session ($request->getSession()->migrate(true) manquant).

        $reponse = $this->redirectionApresConnexion($request);

        // F7.4 : cookie « souvenir » non signé, relu à chaque requête par SouvenirSubscriber.
        if ($request->request->get('souvenir')) {
            $client = $token->getUser();
            $valeur = base64_encode($client->getUserIdentifier().':'.$client->getPassword());
            // Non chiffré, non signé, HttpOnly absent, durée 30 jours.
            $reponse->headers->setCookie(Cookie::create('souvenir', $valeur, strtotime('+30 days'), '/', null, false, false));
        }

        return $reponse;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $this->enregistrerTentative($request, false);

        return parent::onAuthenticationFailure($request, $exception);
    }

    private function redirectionApresConnexion(Request $request): RedirectResponse
    {
        // F15.2 : redirection ouverte — la cible vient de la requête sans contrôle.
        $cible = $request->query->get('redirect') ?? $request->request->get('redirect');
        if (\is_string($cible) && '' !== $cible) {
            return new RedirectResponse($cible);
        }

        return new RedirectResponse($this->urls->generate('app_accueil'));
    }

    private function enregistrerTentative(Request $request, bool $reussie): void
    {
        $tentative = (new TentativeConnexion())
            ->setEmail((string) $request->request->get('email', ''))
            ->setIp($request->getClientIp() ?? '0.0.0.0')
            ->setReussie($reussie)
            ->setAgent(substr((string) $request->headers->get('User-Agent', ''), 0, 255));
        $this->em->persist($tentative);
        $this->em->flush();
    }
}
