<?php

namespace App\Content\Framework;

use App\Content\ContentException;
use App\Content\Framework\Profiles\DockerProfile;
use App\Content\Framework\Profiles\LaravelProfile;
use App\Content\Framework\Profiles\NuxtProfile;
use App\Content\Framework\Profiles\SymfonyProfile;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Les frameworks que ce moteur sait faire tourner. Le conteneur lui passe tout ce qui porte l'étiquette
 * « app.framework » : ajouter un framework, c'est ajouter un FrameworkProfileProvider, pas retoucher
 * une énumération et neuf `match`.
 *
 * Hors conteneur (tests unitaires), le registre retombe sur les quatre profils livrés.
 */
final class FrameworkRegistry
{
    /** Le framework d'un environnement qui n'en déclare pas, et celui dont on emprunte les conventions. */
    public const string DEFAULT = 'symfony';

    /** @var array<string, FrameworkProfile> */
    private array $profiles;

    /**
     * @param iterable<FrameworkProfileProvider> $providers
     */
    public function __construct(
        #[AutowireIterator('app.framework')]
        iterable $providers = [],
    ) {
        $providers = iterator_to_array($providers, false);
        $profiles = [];
        foreach ($providers ?: self::builtin() as $provider) {
            $profile = $provider->profile();
            $profiles[$profile->id] = $profile;
        }
        // Le conteneur livre les services dans l'ordre où il les découvre (alphabétique) : le rang
        // déclaré par chaque profil fixe l'ordre des listes, indépendamment de cette découverte.
        uasort($profiles, static fn (FrameworkProfile $a, FrameworkProfile $b) => $a->order <=> $b->order);
        $this->profiles = $profiles;
        if (!isset($this->profiles[self::DEFAULT])) {
            throw new ContentException(sprintf('Aucun profil « %s » : le moteur ne sait plus quel framework prendre par défaut.', self::DEFAULT));
        }
    }

    public function has(string $id): bool
    {
        return isset($this->profiles[$id]);
    }

    public function get(string $id): FrameworkProfile
    {
        return $this->profiles[$id] ?? throw new ContentException(sprintf('Framework « %s » inconnu (%s).', $id, implode(', ', $this->ids())));
    }

    public function default(): FrameworkProfile
    {
        return $this->profiles[self::DEFAULT];
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->profiles);
    }

    /** @return array<string, FrameworkProfile> */
    public function all(): array
    {
        return $this->profiles;
    }

    /** @return array<string, string> identifiant => libellé, pour les filtres et les listes */
    public function labels(): array
    {
        return array_map(static fn (FrameworkProfile $profile) => $profile->label, $this->profiles);
    }

    /** @return list<FrameworkProfileProvider> */
    private static function builtin(): array
    {
        return [new SymfonyProfile(), new LaravelProfile(), new DockerProfile(), new NuxtProfile()];
    }
}
