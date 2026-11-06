<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * L'adresse du serveur MCP (/mcp), citée par les annuaires MCP, ouverte dans un navigateur : redirigée (301) vers la
 * page qui explique comment l'ajouter à son assistant, dans la langue du navigateur. Les liens de ces annuaires
 * profitent ainsi à une vraie page.
 *
 * Seulement une page demandée en HTML : un client MCP parle en POST, et son GET d'écoute n'attend que
 * text/event-stream. Avant le routeur, qui confierait sinon la requête au serveur MCP.
 */
final readonly class McpBrowserRedirectListener
{
    public const string PATH = '/mcp';

    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 64)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || self::PATH !== $request->getPathInfo() || !\in_array($request->getMethod(), ['GET', 'HEAD'], true) || !self::wantsPage($request)) {
            return;
        }

        $locale = $request->getPreferredLanguage(['en', 'fr']) ?? 'en';
        $response = new RedirectResponse($this->urls->generate('app_scratch_ai', ['_locale' => $locale]), Response::HTTP_MOVED_PERMANENTLY);
        $response->setVary(['Accept', 'Accept-Language']);
        $event->setResponse($response);
    }

    /** Un navigateur : du HTML parmi ce qu'il accepte, et pas le flux d'événements d'un client MCP. */
    private static function wantsPage(Request $request): bool
    {
        $accept = $request->getAcceptableContentTypes();

        return \in_array('text/html', $accept, true) && !\in_array('text/event-stream', $accept, true);
    }
}
