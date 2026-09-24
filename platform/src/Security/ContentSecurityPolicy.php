<?php

namespace App\Security;

use App\Instance\Analytics;
use Pentatrion\ViteBundle\Service\EntrypointsLookupCollection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Politique de sécurité du contenu des pages : d'où peuvent venir les scripts. Filet en cas de faille XSS —
 * un <script> injecté, un attribut onerror=, un script d'un autre site ne s'exécutent pas.
 *
 * Seuls les scripts du site lui-même passent, plus WebAssembly (le PHP du playground). Les pages qui
 * embarquent le playground ou l'atelier y ajoutent 'unsafe-eval' ({{ csp_allow_eval() }} dans leur gabarit) :
 * le simulateur Nuxt compile le code de l'apprenant dans la page. Le code de l'aperçu, lui, n'est pas concerné :
 * il est servi par le Service Worker du bac à sable, sans cet en-tête.
 *
 * CSP_REPORT_ONLY=1 : le navigateur ne bloque rien, il signale seulement à /csp-rapport ce qu'il aurait bloqué.
 */
final class ContentSecurityPolicy
{
    public const string REPORT_PATH = '/csp-rapport';
    private const string EVAL_ATTRIBUTE = '_csp_allow_eval';

    public function __construct(
        private readonly RequestStack $requests,
        private readonly Analytics $analytics,
        private readonly EntrypointsLookupCollection $vite,
        #[Autowire(env: 'bool:CSP_REPORT_ONLY')]
        public readonly bool $reportOnly,
    ) {
    }

    /** Appelé par le gabarit d'une page qui embarque le simulateur Nuxt. */
    public function allowEval(): void
    {
        $this->requests->getMainRequest()?->attributes->set(self::EVAL_ATTRIBUTE, true);
    }

    public function headerName(): string
    {
        return $this->reportOnly ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
    }

    public function policy(Request $request): string
    {
        $scripts = ["'self'", "'wasm-unsafe-eval'"];
        if (true === $request->attributes->get(self::EVAL_ATTRIBUTE)) {
            $scripts[] = "'unsafe-eval'";
        }
        // Une instance peut servir le traceur d'Umami depuis un autre domaine.
        if ($this->analytics->isEnabled() && null !== $origin = self::originOf($this->analytics->scriptUrl)) {
            $scripts[] = $origin;
        }
        // En développement, les scripts viennent du serveur de Vite.
        $lookup = $this->vite->getEntrypointsLookup();
        $viteServer = $lookup->hasFile() ? $lookup->getViteServer() : null;
        if (null !== $viteServer && null !== $origin = self::originOf($viteServer)) {
            $scripts[] = $origin;
        }

        return implode('; ', [
            'script-src '.implode(' ', array_unique($scripts)),
            "object-src 'none'",
            "base-uri 'self'",
            'report-uri '.self::REPORT_PATH,
        ]);
    }

    private static function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
