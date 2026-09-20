<?php

namespace App\Content\Framework;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Fournit un profil de framework au registre. Une classe par framework : c'est le point d'extension du
 * moteur côté serveur — un paquet de plateforme en déclare une, et le moteur la découvre par son étiquette.
 *
 * Contrat encore jeune (voir ANALYSE-MARQUE-BLANCHE.md, « le contrat d'un plugin plateforme ») : les
 * quatre profils livrés sont ses seuls usagers, et il bougera tant qu'un tiers ne l'aura pas essayé.
 */
#[AutoconfigureTag('app.framework')]
interface FrameworkProfileProvider
{
    public function profile(): FrameworkProfile;
}
