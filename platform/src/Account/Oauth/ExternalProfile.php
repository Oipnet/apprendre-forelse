<?php

namespace App\Account\Oauth;

/** Ce que le fournisseur dit du compte qui vient de se connecter. */
final readonly class ExternalProfile
{
    /**
     * @param string       $provider       nom du fournisseur (OauthProvider::name())
     * @param string       $id             identifiant du compte chez le fournisseur, stable
     * @param string       $username       ce qui désigne le compte chez lui, pour l'affichage (login GitHub, adresse…)
     * @param list<string> $verifiedEmails adresses vérifiées par le fournisseur, en minuscules, la principale d'abord
     */
    public function __construct(
        public string $provider,
        public string $id,
        public string $username,
        public ?string $name,
        public array $verifiedEmails,
    ) {
    }

    /** L'adresse d'un nouveau compte : la principale, si le fournisseur l'a vérifiée, sinon la première vérifiée. */
    public function primaryEmail(): ?string
    {
        return $this->verifiedEmails[0] ?? null;
    }

    /** Le pseudo proposé : le nom affiché chez le fournisseur, à défaut son identifiant, coupé à la longueur d'un pseudo. */
    public function suggestedDisplayName(): string
    {
        $name = trim((string) $this->name);
        if ('' === $name) {
            // Une adresse ne fait pas un pseudo : on n'en garde que la partie avant « @ ».
            $name = strstr($this->username, '@', true) ?: $this->username;
        }

        return mb_substr($name, 0, 40);
    }
}
