<?php

namespace App\Tests\Mcp;

use App\Instance\Analytics;
use App\Mcp\McpAnalytics;
use App\Scratch\ScratchLink;
use Psr\Log\NullLogger;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Le serveur MCP du bac à sable (/mcp), appelé comme un assistant IA l'appelle : poignée de main, liste des outils,
 * puis un lien fabriqué et relu. Rien n'est stocké : le lien porte le code.
 */
final class McpServerTest extends WebTestCase
{
    use DatabaseTrait;

    private KernelBrowser $client;
    private ?string $session = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testUnAssistantDecouvreLesTroisOutils(): void
    {
        $init = $this->handshake();

        $this->assertSame('php-playground', $init['result']['serverInfo']['name']);
        $this->assertStringContainsString('create_sandbox', $init['result']['instructions']);

        $tools = $this->call('tools/list')['result']['tools'];
        $this->assertSame(['create_sandbox', 'get_starter', 'read_sandbox'], $this->sorted(array_column($tools, 'name')));
        $create = $tools[array_search('create_sandbox', array_column($tools, 'name'), true)];
        $this->assertSame(['symfony', 'laravel', 'php'], $create['inputSchema']['properties']['framework']['enum']);
        $this->assertSame('object', $create['inputSchema']['properties']['files']['type']);
        $this->assertSame(['framework', 'files'], $create['inputSchema']['required']);
    }

    public function testLeProjetDeDepartDonneSesFichiersEtCeQuOnPeutModifier(): void
    {
        $this->handshake();

        $starter = $this->tool('get_starter', ['framework' => 'symfony']);

        $this->assertSame('http://localhost/en/playground/symfony?utm_source=mcp&utm_medium=ia&utm_campaign=bac-a-sable', $starter['url']);
        $this->assertArrayHasKey('src/Controller/AccueilController.php', $starter['files']);
        $this->assertContains('src/*/*.php', $starter['editable_paths']);
        $this->assertContains('symfony/security-bundle', array_column($starter['packages'], 'name'), 'Les paquets du bac à sable Symfony, ajoutés avec leur recette Flex.');
        $this->assertStringStartsWith('# Symfony playground', $starter['about']);
        $this->assertContains('config/services.yaml', $starter['config_files']);
        $this->assertArrayNotHasKey('config/services.yaml', $starter['files'], 'La configuration n\'est envoyée qu\'à la demande.');
        $this->assertStringContainsString('App\\:', $this->tool('get_starter', ['framework' => 'symfony', 'include_config' => true])['files']['config/services.yaml']);

        $php = $this->tool('get_starter', ['framework' => 'php', 'language' => 'fr']);
        $this->assertStringStartsWith('http://localhost/bac-a-sable/php?', $php['url']);
        $this->assertContains('nesbot/carbon', array_column($php['packages'], 'name'), 'Le catalogue des paquets du bac à sable PHP.');
    }

    public function testUnLienFabriqueNePorteQueLesFichiersModifiesEtSeRelit(): void
    {
        $this->handshake();
        $starter = $this->tool('get_starter', ['framework' => 'php', 'language' => 'fr'])['files'];
        $index = "<?php\n\necho 'Bonjour é 🎉';\n";

        $created = $this->tool('create_sandbox', [
            'framework' => 'php',
            'files' => ['public/index.php' => $index, 'src/Salutation.php' => $starter['src/Salutation.php'], 'src/Service/Horloge.php' => "<?php\n\nnamespace App\\Service;\n\nfinal class Horloge {}\n"],
            'packages' => ['nesbot/carbon'],
            'language' => 'fr',
        ]);

        $this->assertMatchesRegularExpression('~^http://localhost/bac-a-sable/php\?utm_source=mcp[^#]*#code=[A-Za-z0-9_-]+$~', $created['url']);
        $this->assertSame(['public/index.php', 'src/Service/Horloge.php'], $created['files']);
        $this->assertSame(['src/Salutation.php'], $created['unchanged'], 'Un fichier identique au départ ne charge pas le lien.');
        $this->assertSame(['public/index.php' => $index, 'src/Service/Horloge.php' => "<?php\n\nnamespace App\\Service;\n\nfinal class Horloge {}\n"], (new ScratchLink())->decode($created['url'])['files']);

        $read = $this->tool('read_sandbox', ['url' => $created['url']]);
        $this->assertSame('php', $read['framework']);
        $this->assertSame('fr', $read['language']);
        $this->assertSame($index, $read['files']['public/index.php']);
        $this->assertSame(['nesbot/carbon'], $read['packages']);

        $symfony = $this->tool('create_sandbox', ['framework' => 'symfony', 'files' => ['src/Controller/AccueilController.php' => '<?php'], 'packages' => ['symfony/security-bundle']]);
        $this->assertSame(['symfony/security-bundle'], (new ScratchLink())->decode($symfony['url'])['packages'], 'Symfony : un paquet du catalogue, sa recette Flex s\'appliquera à l\'ouverture.');
    }

    public function testUnBugQuiDependDeLaConfigurationSeReproduit(): void
    {
        $this->handshake();
        $services = $this->tool('get_starter', ['framework' => 'symfony', 'include_config' => true])['files']['config/services.yaml'];
        $changed = $services."\n    App\\Service\\Horloge:\n        arguments: ['%kernel.debug%']\n";

        $created = $this->tool('create_sandbox', ['framework' => 'symfony', 'files' => [
            'config/services.yaml' => $changed,
            'config/packages/framework.yaml' => $this->tool('get_starter', ['framework' => 'symfony', 'include_config' => true])['files']['config/packages/framework.yaml'],
        ]]);

        $this->assertSame(['config/services.yaml'], $created['files']);
        $this->assertSame(['config/packages/framework.yaml'], $created['unchanged']);
        $this->assertSame(['config/services.yaml' => $changed], (new ScratchLink())->decode($created['url'])['files']);
    }

    public function testCeQueLaPageNAccepteraitPasEstRefuseAvecLaRaison(): void
    {
        $this->handshake();

        $config = $this->toolError('create_sandbox', ['framework' => 'symfony', 'files' => ['config/bundles.php' => '<?php return [];']]);
        $this->assertStringContainsString('config/bundles.php', $config, 'Les bundles dépendent des paquets installés.');
        $this->assertStringContainsString('src/*/*.php', $config, 'L\'assistant apprend ce qu\'il peut modifier.');

        $this->assertStringContainsString('no package can be added', $this->toolError('create_sandbox', ['framework' => 'laravel', 'files' => ['routes/web.php' => '<?php'], 'packages' => ['nesbot/carbon']]));
        $this->assertStringContainsString('Unknown packages: evil/package', $this->toolError('create_sandbox', ['framework' => 'php', 'files' => ['public/index.php' => '<?php'], 'packages' => ['evil/package']]));
        $this->assertStringContainsString('not a playground link', $this->toolError('read_sandbox', ['url' => 'https://example.com/#code=abc']));
        $this->assertStringContainsString('carries no code', $this->toolError('read_sandbox', ['url' => 'https://apprendre.forelse.fr/bac-a-sable/symfony#nouveau']));
    }

    public function testUnClientMcpDansUnNavigateurNEstPasRefusePourSonOrigine(): void
    {
        $this->client->request('POST', '/mcp', server: $this->headers() + ['HTTP_ORIGIN' => 'http://localhost:6274'], content: json_encode($this->initialize(), \JSON_THROW_ON_ERROR));

        $this->assertResponseIsSuccessful();
    }

    public function testAuDelaDuQuotaDUneAdresseIpLeServeurDemandeDAttendre(): void
    {
        static::getContainer()->get('limiter.mcp')->create('127.0.0.1')->consume(300);

        $this->client->request('POST', '/mcp', server: $this->headers(), content: json_encode($this->initialize(), \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(429);
        $this->assertResponseHasHeader('Retry-After');
    }

    public function testChaqueLienCreeEstMesureDansUmamiSansLeCode(): void
    {
        $sent = [];
        $umami = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent) {
            $sent[] = ['url' => $url, 'headers' => $options['headers'], 'body' => json_decode($options['body'], true)];

            return new MockResponse('{}');
        });
        $this->client->disableReboot();
        static::getContainer()->set(McpAnalytics::class, new McpAnalytics(new Analytics('/mesure/traceur.js', 'site-forelse'), $umami, static::getContainer()->get('request_stack'), new NullLogger()));
        $this->handshake();

        $this->tool('create_sandbox', ['framework' => 'laravel', 'files' => ['routes/web.php' => '<?php // secret'], 'language' => 'fr']);

        $this->assertCount(1, $sent);
        $this->assertSame('http://umami:3000/api/send', $sent[0]['url']);
        $this->assertContains('User-Agent: '.McpAnalytics::USER_AGENT, $sent[0]['headers']);
        $this->assertSame('site-forelse', $sent[0]['body']['payload']['website']);
        $this->assertSame('mcp-lien-cree', $sent[0]['body']['payload']['name']);
        $this->assertSame(['framework' => 'laravel', 'langue' => 'fr', 'fichiers' => 1, 'paquets' => 0, 'client' => 'test'], $sent[0]['body']['payload']['data'], 'Le client annoncé à la poignée de main, et rien du code.');
    }

    public function testUnUmamiInjoignableNeFaitPasEchouerLOutil(): void
    {
        $this->client->disableReboot();
        static::getContainer()->set(McpAnalytics::class, new McpAnalytics(new Analytics('/mesure/traceur.js', 'site-forelse'), new MockHttpClient(new MockResponse('', ['error' => 'Connexion refusée'])), static::getContainer()->get('request_stack'), new NullLogger()));
        $this->handshake();

        $this->assertSame('php', $this->tool('read_sandbox', ['url' => 'https://apprendre.forelse.fr/bac-a-sable/php#'.(new ScratchLink())->encode(['public/index.php' => '<?php'])])['framework']);
    }

    /** @return array<string, mixed> la réponse à « initialize » */
    private function handshake(): array
    {
        $this->client->request('POST', '/mcp', server: $this->headers(), content: json_encode($this->initialize(), \JSON_THROW_ON_ERROR));
        $this->assertResponseIsSuccessful();
        $this->session = $this->client->getResponse()->headers->get('Mcp-Session-Id');
        $init = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        $this->client->request('POST', '/mcp', server: $this->headers(), content: json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(202);

        return $init;
    }

    /** @return array<string, mixed> */
    private function initialize(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ]];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function call(string $method, array $params = []): array
    {
        $this->client->request('POST', '/mcp', server: $this->headers(), content: json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => $method, 'params' => (object) $params], \JSON_THROW_ON_ERROR));
        $this->assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> le contenu structuré du résultat
     */
    private function tool(string $name, array $arguments): array
    {
        $result = $this->call('tools/call', ['name' => $name, 'arguments' => $arguments])['result'];
        $this->assertFalse($result['isError'] ?? false, json_encode($result, \JSON_THROW_ON_ERROR));

        return $result['structuredContent'];
    }

    /** @param array<string, mixed> $arguments */
    private function toolError(string $name, array $arguments): string
    {
        $result = $this->call('tools/call', ['name' => $name, 'arguments' => $arguments])['result'];
        $this->assertTrue($result['isError'] ?? false, json_encode($result, \JSON_THROW_ON_ERROR));

        return $result['content'][0]['text'];
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => null === $this->session ? null : '2025-06-18',
            'HTTP_MCP_SESSION_ID' => $this->session,
        ]);
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        sort($names);

        return $names;
    }
}
