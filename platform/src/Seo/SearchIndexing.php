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
        'app_tracks',
        'app_track',
        'app_chapter_summary',
        'app_exercise',
        'app_concepts',
        'app_concept',
        'app_practice',
        'app_practice_version',
        'app_exercise_pratique',
        'app_scratch',
        'app_scratch_symfony',
        'app_scratch_laravel',
        'app_blog',
        'app_article',
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

    /** Filtre du catalogue des parcours : même règle. */
    public const array TRACK_FILTERS = ['framework'];

    /** Filtre du blog par sujet : même règle. */
    public const array BLOG_FILTERS = ['sujet'];

    /** Attribut de requête posé par markThin() : une page publique trop mince pour être proposée aux moteurs. */
    private const string THIN = '_seo_thin';

    public const string NOINDEX = 'noindex';
    public const string NOINDEX_FOLLOW = 'noindex, follow';

    public function __construct(
        private SandboxOrigin $sandbox,
        #[Autowire(env: 'bool:SEARCH_INDEXING')]
        private bool $enabled,
    ) {
    }

    /** Une page publique, en liste blanche (ROUTES). */
    public static function isPublic(Request $request): bool
    {
        return \in_array(self::route($request), self::ROUTES, true);
    }

    /** Le nom de la route, sans le suffixe de langue d'une route traduite (app_blog.en : app_blog). */
    public static function route(Request $request): mixed
    {
        return $request->attributes->get('_canonical_route') ?? $request->attributes->get('_route');
    }

    /** L'instance accepte-t-elle d'être indexée, sur cette origine ? */
    public function isEnabled(Request $request): bool
    {
        return $this->enabled && !$this->sandbox->isSandboxRequest($request);
    }

    /**
     * Une page publique sans texte à elle (une notion sans intro) : ses liens se suivent, elle ne s'indexe pas.
     * Mieux vaut quelques pages fournies que beaucoup de listes que les moteurs jugent minces.
     */
    public static function markThin(Request $request): void
    {
        $request->attributes->set(self::THIN, true);
    }

    /** La directive robots de la requête, ou null si la page s'indexe normalement. */
    public function directive(Request $request, int $status = 200): ?string
    {
        if (!$this->isEnabled($request) || $status >= 400 || !self::isPublic($request)) {
            return self::NOINDEX;
        }
        $filters = match (self::route($request)) {
            'app_practice' => self::PRACTICE_FILTERS,
            'app_tracks' => self::TRACK_FILTERS,
            'app_blog' => self::BLOG_FILTERS,
            default => [],
        };
        if ($request->attributes->getBoolean(self::THIN) || array_intersect($filters, array_keys($request->query->all()))) {
            return self::NOINDEX_FOLLOW;
        }

        return null;
    }
}
