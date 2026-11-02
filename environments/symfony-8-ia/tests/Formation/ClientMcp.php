<?php

namespace App\Tests\Formation;

use PHPUnit\Framework\Attributes\Before;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Outil des tests d'exercices du parcours IA : parler au serveur MCP de l'application comme le ferait une IA cliente
 * (Claude Desktop, Cursor…), en JSON-RPC sur HTTP.
 *
 *     $outils = $this->outilsMcp();                                  // initialize, puis tools/list
 *     $resultat = $this->appelerOutilMcp('thes_du_catalogue', []);   // tools/call
 *
 * La première requête ouvre une session (initialize, puis notifications/initialized) ; les suivantes portent
 * l'en-tête Mcp-Session-Id que le serveur a renvoyé.
 */
trait ClientMcp
{
    private ?string $sessionMcp = null;

    /** @var array<string, string> en-têtes HTTP ajoutés à chaque requête MCP (au format de $_SERVER) */
    private array $entetesMcp = [];

    private int $idMcp = 0;

    #[Before]
    protected function oublierLaSessionMcp(): void
    {
        $this->sessionMcp = null;
        $this->entetesMcp = [];
        $this->idMcp = 0;
    }

    /** Le jeton envoyé dans l'en-tête Authorization: Bearer, ou null pour n'en envoyer aucun. */
    protected function jetonMcp(?string $jeton): void
    {
        unset($this->entetesMcp['HTTP_AUTHORIZATION']);
        if (null !== $jeton) {
            $this->entetesMcp['HTTP_AUTHORIZATION'] = 'Bearer '.$jeton;
        }
        $this->sessionMcp = null;
    }

    /** L'adresse IP d'où partent les requêtes MCP (REMOTE_ADDR), pour les tests d'un limiteur de débit. */
    protected function adresseMcp(string $ip): void
    {
        $this->entetesMcp['REMOTE_ADDR'] = $ip;
    }

    /** Envoie initialize et renvoie le seul statut HTTP, sans rien exiger : pour vérifier qu'un serveur refuse. */
    protected function statutDInitialisationMcp(): int
    {
        $this->envoyerInitialize();

        return $this->statutMcp();
    }

    /**
     * Le résultat de initialize : protocolVersion, capabilities, serverInfo.
     *
     * @return array<string, mixed>
     */
    protected function initialiserMcp(): array
    {
        $reponse = $this->envoyerInitialize();
        self::assertSame(200, $this->clientMcp()->getResponse()->getStatusCode(), 'Le serveur MCP doit répondre à initialize sur /mcp : '.$this->clientMcp()->getResponse()->getContent());
        self::assertArrayHasKey('result', $reponse, 'initialize doit renvoyer un résultat : '.json_encode($reponse));

        $this->sessionMcp = $this->clientMcp()->getResponse()->headers->get('Mcp-Session-Id');
        $this->envoyerMcp('notifications/initialized', [], notification: true);

        return $reponse['result'];
    }

    /**
     * Les outils du serveur (tools/list), indexés par leur nom.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function outilsMcp(): array
    {
        $reponse = $this->requeteMcp('tools/list');
        self::assertArrayHasKey('result', $reponse, 'tools/list doit renvoyer un résultat : '.json_encode($reponse));

        return array_column($reponse['result']['tools'], null, 'name');
    }

    /**
     * Le résultat de tools/call : content (liste de blocs), isError, et structuredContent s'il y en a un.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    protected function appelerOutilMcp(string $outil, array $arguments = []): array
    {
        $reponse = $this->requeteMcp('tools/call', ['name' => $outil, 'arguments' => (object) $arguments]);
        self::assertArrayHasKey('result', $reponse, \sprintf('tools/call sur %s doit renvoyer un résultat : %s', $outil, json_encode($reponse, \JSON_UNESCAPED_UNICODE)));

        return $reponse['result'];
    }

    /** Le texte des blocs « text » d'un résultat de tools/call, mis bout à bout. */
    protected static function texteMcp(array $resultat): string
    {
        return implode("\n", array_map(static fn (array $bloc): string => $bloc['text'] ?? '', $resultat['content'] ?? []));
    }

    /**
     * Une requête JSON-RPC dans la session MCP (ouverte au besoin) : la réponse décodée, result ou error.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    protected function requeteMcp(string $methode, array $params = []): array
    {
        if (null === $this->sessionMcp) {
            $this->initialiserMcp();
        }

        return $this->envoyerMcp($methode, $params);
    }

    /** Le statut HTTP de la dernière requête MCP. */
    protected function statutMcp(): int
    {
        return $this->clientMcp()->getResponse()->getStatusCode();
    }

    /** @return array<string, mixed> */
    private function envoyerInitialize(): array
    {
        return $this->envoyerMcp('initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'tests-forelse', 'version' => '1.0'],
        ], sansSession: true);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function envoyerMcp(string $methode, array $params, bool $notification = false, bool $sansSession = false): array
    {
        $corps = ['jsonrpc' => '2.0', 'method' => $methode, 'params' => (object) $params];
        if (!$notification) {
            $corps['id'] = ++$this->idMcp;
        }
        $entetes = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', ...$this->entetesMcp];
        if (!$sansSession && null !== $this->sessionMcp) {
            $entetes['HTTP_MCP_SESSION_ID'] = $this->sessionMcp;
        }

        $this->clientMcp()->request('POST', '/mcp', [], [], $entetes, json_encode($corps, \JSON_THROW_ON_ERROR));
        $contenu = (string) $this->clientMcp()->getResponse()->getContent();

        return '' === $contenu ? [] : (json_decode($contenu, true) ?? ['brut' => $contenu]);
    }

    private function clientMcp(): KernelBrowser
    {
        return static::getClient() ?? static::createClient();
    }
}
