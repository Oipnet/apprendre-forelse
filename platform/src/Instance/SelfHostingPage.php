<?php

namespace App\Instance;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Page « Auto-hébergement » : le moteur libre et l'offre de Forelse autour de lui (ses parcours, sur devis).
 * Réservée à l'instance de Forelse (SELF_HOSTING_PAGE=1) : une autre instance n'a pas à la montrer.
 */
final readonly class SelfHostingPage
{
    public const string REPOSITORY = 'https://github.com/oipnet/apprendre-forelse';
    public const string GUIDE = self::REPOSITORY.'/blob/main/auto-hebergement/README.md';
    public const string LICENSE = self::REPOSITORY.'/blob/main/LICENSE';

    public function __construct(
        #[Autowire(env: 'bool:SELF_HOSTING_PAGE')]
        public bool $enabled,
    ) {
    }
}
