<?php

namespace App\Account\Oauth;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Les fournisseurs d'identité connus, dans l'ordre des boutons. Exposé aux gabarits (global Twig « oauth ») : un
 * fournisseur sans identifiants n'y apparaît pas, ni bouton, ni mention dans la politique de confidentialité.
 */
final readonly class OauthProviders
{
    /** @var array<string, OauthProvider> */
    private array $providers;

    /** @param iterable<OauthProvider> $providers */
    public function __construct(
        #[AutowireIterator(OauthProvider::TAG)]
        iterable $providers,
    ) {
        $byName = [];
        foreach ($providers as $provider) {
            $byName[$provider->name()] = $provider;
        }
        // GitHub d'abord : c'est celui que les développeurs attendent.
        uksort($byName, static fn (string $a, string $b) => array_search($a, ['github', 'google', 'linkedin'], true) <=> array_search($b, ['github', 'google', 'linkedin'], true));
        $this->providers = $byName;
    }

    /** Le fournisseur activé qui porte ce nom, ou null (inconnu, ou sans identifiants). */
    public function get(string $name): ?OauthProvider
    {
        $provider = $this->providers[$name] ?? null;

        return null !== $provider && $provider->isEnabled() ? $provider : null;
    }

    /** @return list<OauthProvider> les fournisseurs activés */
    public function enabled(): array
    {
        return array_values(array_filter($this->providers, static fn (OauthProvider $provider) => $provider->isEnabled()));
    }

    /** Le nom affiché d'un fournisseur connu, activé ou non (un compte peut rester lié à un fournisseur retiré). */
    public function label(string $name): string
    {
        return isset($this->providers[$name]) ? $this->providers[$name]->label() : $name;
    }
}
