<?php

namespace App\Account\Github;

/** Ce que GitHub dit du compte qui vient de se connecter. */
final readonly class GithubProfile
{
    /**
     * @param string       $id             identifiant numérique du compte GitHub, stable
     * @param list<string> $verifiedEmails adresses vérifiées par GitHub, en minuscules, la principale d'abord
     */
    public function __construct(
        public string $id,
        public string $login,
        public ?string $name,
        public array $verifiedEmails,
    ) {
    }

    /** L'adresse d'un nouveau compte : la principale, si GitHub l'a vérifiée, sinon la première vérifiée. */
    public function primaryEmail(): ?string
    {
        return $this->verifiedEmails[0] ?? null;
    }

    /** Le pseudo proposé : le nom affiché sur GitHub, à défaut l'identifiant, coupé à la longueur d'un pseudo. */
    public function suggestedDisplayName(): string
    {
        $name = trim((string) $this->name);

        return mb_substr('' !== $name ? $name : $this->login, 0, 40);
    }
}
