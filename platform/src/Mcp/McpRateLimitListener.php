<?php

namespace App\Mcp;

use App\EventListener\OriginIsolationListener;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Le serveur MCP est ouvert à tous, sans compte : chaque adresse IP a son quota (limiteur « mcp »). Au-delà, une
 * réponse 429 avec Retry-After, que les clients MCP savent attendre.
 */
final readonly class McpRateLimitListener
{
    public function __construct(
        #[Autowire(service: 'limiter.mcp')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 200)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || OriginIsolationListener::MCP_PATH !== $request->getPathInfo() || $request->isMethod('OPTIONS')) {
            return;
        }
        $limit = $this->limiter->create($request->getClientIp() ?? 'inconnue')->consume();
        if ($limit->isAccepted()) {
            return;
        }
        $event->setResponse(new Response('Too many requests to the MCP server, retry later.', Response::HTTP_TOO_MANY_REQUESTS, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
        ]));
    }
}
