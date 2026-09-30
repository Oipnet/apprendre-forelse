<?php

namespace App\Account\Oauth;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Un fournisseur d'identité avec lequel on se connecte sans mot de passe (OAuth 2, ou OpenID Connect par-dessus) :
 * l'adresse où envoyer l'apprenant, puis l'échange du code reçu au retour contre son profil. Les règles (quel
 * compte, lier ou non) sont communes à tous : voir ExternalSignIn.
 *
 * Chaque fournisseur est facultatif : sans ses identifiants, il n'apparaît nulle part (voir OauthProviders).
 */
#[AutoconfigureTag(self::TAG)]
interface OauthProvider
{
    public const string TAG = 'app.oauth_provider';

    /** Son nom dans les adresses et en base (ExternalIdentity::$provider) : « github », « google », « linkedin ». */
    public function name(): string;

    /** Son nom affiché : « GitHub », « Google », « LinkedIn ». */
    public function label(): string;

    public function isEnabled(): bool;

    /** L'adresse du fournisseur où l'apprenant accepte de se connecter ; il revient sur $redirectUri avec un code. */
    public function authorizationUrl(string $state, string $redirectUri): string;

    /**
     * Échange le code du retour contre un jeton, puis lit le profil et les adresses vérifiées. Le jeton n'est pas
     * gardé : il ne sert qu'à cette lecture.
     *
     * @throws OauthException
     */
    public function fetchProfile(string $code, string $redirectUri): ExternalProfile;
}
