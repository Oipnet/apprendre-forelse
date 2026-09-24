<?php

namespace App\Controller;

use App\Security\ContentSecurityPolicy;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ce que la politique de sécurité du contenu a bloqué (ou aurait bloqué, en CSP_REPORT_ONLY=1) : une ligne par
 * rapport dans les journaux, pour vérifier qu'elle ne casse rien avant de la rendre bloquante.
 */
final class CspReportController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.csp_report')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route(ContentSecurityPolicy::REPORT_PATH, name: 'app_csp_report', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if ($this->limiter->create($request->getClientIp() ?? 'inconnu')->consume()->isAccepted()) {
            $report = json_decode(substr($request->getContent(), 0, 8192), true)['csp-report'] ?? null;
            if (\is_array($report)) {
                $this->logger->warning('CSP : {directive} a bloqué {blocked} sur {page}', [
                    'directive' => (string) ($report['violated-directive'] ?? $report['effective-directive'] ?? '?'),
                    'blocked' => (string) ($report['blocked-uri'] ?? '?'),
                    'page' => (string) ($report['document-uri'] ?? '?'),
                    'source' => isset($report['source-file']) ? $report['source-file'].':'.($report['line-number'] ?? '?') : null,
                    'sample' => $report['script-sample'] ?? null,
                ]);
            }
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
