<?php

namespace App\Mcp;

use App\Instance\Analytics;
use Mcp\Server\RequestContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Les appels au serveur MCP dans la mesure d'audience : Umami ne voit que les navigateurs, et un assistant IA n'en
 * est pas un. Chaque appel d'outil devient un événement (mcp-depart, mcp-lien-cree, mcp-lien-lu), envoyé au point
 * de collecte d'Umami depuis le serveur, comme le ferait le traceur : framework, langue, client MCP (Claude,
 * Cursor…). Aucun code ni contenu de fichier n'y part.
 *
 * Éteint comme le reste de la mesure (Analytics::isEnabled) ; un Umami absent ou lent ne retarde l'outil que d'une
 * seconde au plus, et ne le fait jamais échouer.
 */
final readonly class McpAnalytics
{
    /** Ni « bot » ni « http » : Umami écarte les agents qui ressemblent à des robots. */
    public const string USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) Forelse-MCP/1.0';

    public function __construct(
        private Analytics $analytics,
        private HttpClientInterface $httpClient,
        private RequestStack $requests,
        private LoggerInterface $logger,
        /** Umami dans le réseau Docker, comme pour docker/Caddyfile. */
        #[Autowire('%env(default::ANALYTICS_UPSTREAM)%')]
        private ?string $upstream = null,
    ) {
    }

    /** @param array<string, string|int> $data */
    public function track(string $name, array $data, ?RequestContext $context = null): void
    {
        if (!$this->analytics->isEnabled()) {
            return;
        }
        $request = $this->requests->getMainRequest();
        try {
            $this->httpClient->request('POST', 'http://'.($this->upstream ?: 'umami:3000').'/api/send', [
                'timeout' => 1,
                'max_duration' => 1,
                'headers' => array_filter([
                    'User-Agent' => self::USER_AGENT,
                    // Le pays et le visiteur, comme pour une page : l'adresse n'est pas conservée par Umami.
                    'X-Forwarded-For' => $request?->getClientIp(),
                ]),
                'json' => ['type' => 'event', 'payload' => [
                    'website' => $this->analytics->websiteId,
                    'hostname' => $request?->getHost() ?? 'localhost',
                    'url' => '/mcp',
                    'title' => 'MCP',
                    'language' => '',
                    'screen' => '',
                    'referrer' => '',
                    'name' => $name,
                    'data' => [...$data, 'client' => $this->client($context)],
                ]],
            ])->getStatusCode();
        } catch (\Throwable $e) {
            $this->logger->info('Événement MCP non mesuré : {message}', ['message' => $e->getMessage()]);
        }
    }

    /** Le client MCP annoncé à la poignée de main (« claude-ai », « cursor-vscode »…), ou « inconnu ». */
    private function client(?RequestContext $context): string
    {
        try {
            $info = $context?->getSession()->get('client_info');
        } catch (\Throwable) {
            $info = null;
        }

        return \is_array($info) && \is_string($info['name'] ?? null) && '' !== $info['name'] ? mb_substr($info['name'], 0, 60) : 'inconnu';
    }
}
