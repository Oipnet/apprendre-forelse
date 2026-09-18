<?php

namespace App\Instance;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Mesure d'audience : pages vues, référents, pays. Umami auto-hébergé sur le même serveur, sans cookie
 * ni adresse IP conservée — donc dispensé de consentement, et pas de bandeau à afficher (voir
 * /confidentialite). Le script est servi par la plateforme elle-même (docker/Caddyfile) : même origine
 * que le site, rien qui ressemble à un traceur tiers pour un bloqueur de publicités.
 *
 * Les deux variables vides (le cas par défaut, et celui de toute instance auto-hébergée qui n'en veut
 * pas) : aucune balise dans les pages, rien n'est mesuré.
 */
final readonly class Analytics
{
    public function __construct(
        /** URL du script, servi sous le même domaine (ex. /mesure/traceur.js). Le point de collecte en découle : son dossier + /api/send. */
        #[Autowire(env: 'ANALYTICS_SCRIPT_URL')]
        public string $scriptUrl,
        /** Identifiant du site, créé dans le tableau de bord d'Umami. */
        #[Autowire(env: 'ANALYTICS_WEBSITE_ID')]
        public string $websiteId,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== $this->scriptUrl && '' !== $this->websiteId;
    }
}
