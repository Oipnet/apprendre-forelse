<?php

namespace App\Seo;

use App\Security\SandboxOrigin;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Ce que les moteurs de recherche ont le droit d'indexer. Une liste blanche de routes publiques : une page ajoutée
 * plus tard reste hors de l'index tant qu'on ne l'y a pas mise.
 *
 * Rien n'est indexable sur le bac à sable (le code des apprenants), ni sur une instance qui refuse l'indexation
 * (SEARCH_INDEXING=0 : préproduction, instance d'école).
 */
final readonly class SearchIndexing
{
    /** Routes indexables : ce qu'un visiteur sans compte peut lire. */
    public const array ROUTES = [
        'app_home',
        'app_track',
        'app_exercise',
        'app_practice',
        'app_exercise_pratique',
        'app_legal_notice',
        'app_privacy',
        'app_terms',
        'app_contact',
        'app_organizations',
        'app_self_hosting',
        'app_robots',
        'app_sitemap',
    ];

    /** Filtres de la liste de Pratique : la page filtrée se suit, mais ne s'indexe pas. */
    public const array PRACTICE_FILTERS = ['framework', 'notion', 'nouveautes'];

    public const string NOINDEX = 'noindex';
    public const string NOINDEX_FOLLOW = 'noindex, follow';

    public function __construct(
        private SandboxOrigin $sandbox,
        #[Autowire(env: 'bool:SEARCH_INDEXING')]
        private bool $enabled,
    ) {
    }

    /** L'instance accepte-t-elle d'être indexée, sur cette origine ? */
    public function isEnabled(Request $request): bool
    {
        return $this->enabled && !$this->sandbox->isSandboxRequest($request);
    }

    /** La directive robots de la requête, ou null si la page s'indexe normalement. */
    public function directive(Request $request, int $status = 200): ?string
    {
        if (!$this->isEnabled($request) || $status >= 400 || !\in_array($request->attributes->get('_route'), self::ROUTES, true)) {
            return self::NOINDEX;
        }
        if ('app_practice' === $request->attributes->get('_route') && array_intersect(self::PRACTICE_FILTERS, array_keys($request->query->all()))) {
            return self::NOINDEX_FOLLOW;
        }

        return null;
    }
}
