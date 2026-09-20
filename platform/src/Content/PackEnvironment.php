<?php

namespace App\Content;

/**
 * Un environnement d'exécution qu'un pack déclare nécessaire, et où aller le chercher.
 *
 * C'est la clé `environments:` de `pack.yaml`. Un pack ne porte pas son décor : il dit de quoi il a
 * besoin et d'où cela vient, et le moteur l'installe s'il ne l'a pas déjà (voir
 * App\Instance\PackEnvironments). Une dépendance déclarée, comme la contrainte `moteur:` — à ceci près
 * que celle-ci se résout au lieu de se contenter d'échouer.
 */
final readonly class PackEnvironment
{
    public function __construct(
        /** L'identifiant attendu : celui que les exercices du pack écrivent dans « environment: ». */
        public string $id,
        /** L'adresse https du dépôt qui porte cet environnement. */
        public string $depot,
        /** Branche ou étiquette. Vide : la branche par défaut du dépôt. */
        public string $ref,
        /** Le pack qui le demande, pour que les messages disent à qui s'adresser. */
        public string $packId,
    ) {
    }

    /** Deux packs peuvent demander le même environnement, à condition de le demander au même endroit. */
    public function sameSourceAs(self $other): bool
    {
        return $this->depot === $other->depot && $this->ref === $other->ref;
    }

    /** L'adresse telle qu'on peut l'afficher : sans le jeton d'un dépôt privé. */
    public function displayDepot(): string
    {
        return RepositoryUrl::withoutCredentials($this->depot);
    }

    public function describeSource(): string
    {
        return $this->displayDepot().('' === $this->ref ? '' : ' ('.$this->ref.')');
    }
}
