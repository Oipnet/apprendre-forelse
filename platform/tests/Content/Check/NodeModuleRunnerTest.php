<?php

namespace App\Tests\Content\Check;

use App\Content\Check\NodeModuleRunner;
use App\Content\Check\ProcessEnvironment;
use App\Content\Environment;
use App\Content\Framework\FrameworkProfile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;

/** Le code de test d'un pack, lancé par Node, ne voit pas les variables de la plateforme (comme avec PHPUnit). */
final class NodeModuleRunnerTest extends TestCase
{
    /** Des variables de la plateforme, posées comme en production : de vraies variables d'environnement. */
    private const array SECRETS = [
        'APP_SECRET' => 'secret-de-la-plateforme',
        'DATABASE_URL' => 'postgresql://prod:mot-de-passe@db/formation',
        'STRIPE_SECRET_KEY' => 'sk_live_secret',
        'ANTHROPIC_API_KEY' => 'sk-ant-secret',
    ];

    private string $dir;
    /** @var array<string, array{string|false, mixed, mixed}> valeurs d'origine : getenv(), $_SERVER, $_ENV */
    private array $original = [];

    protected function setUp(): void
    {
        if (null === (new ExecutableFinder())->find('node')) {
            $this->markTestSkipped('Node.js est introuvable.');
        }
        $this->dir = sys_get_temp_dir().'/node-runner-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        foreach (self::SECRETS as $name => $value) {
            $this->original[$name] = [getenv($name), $_SERVER[$name] ?? null, $_ENV[$name] ?? null];
            putenv($name.'='.$value);
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $name => [$env, $server, $envArray]) {
            false === $env ? putenv($name) : putenv($name.'='.$env);
            if (null === $server) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
            if (null === $envArray) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $envArray;
            }
        }
        (new Filesystem())->remove($this->dir);
    }

    public function testNodeNeVoitPasLesVariablesDeLaPlateforme(): void
    {
        // Le « module de test » d'un framework : il rapporte, pour chaque variable, si elle est visible.
        $module = $this->dir.'/lanceur.mjs';
        file_put_contents($module, sprintf(<<<'JS'
            const names = %s;
            const cases = names.map((name) => ({ name, status: name in process.env ? 'failed' : 'passed', message: 'visible' }));
            cases.push({ name: 'PATH', status: 'PATH' in process.env ? 'passed' : 'failed' });
            process.stdout.write(JSON.stringify({ cases }));
            JS, json_encode([...array_keys(self::SECRETS), 'NODE_ENV', 'APP_ENV'])));
        putenv('NODE_ENV=production');

        $report = (new NodeModuleRunner(new ProcessEnvironment(\dirname(__DIR__, 3)), \dirname(__DIR__, 3)))
            ->run($this->environment('file://'.$module), $this->dir);
        putenv('NODE_ENV');

        $this->assertSame([], $report->failures, 'Variables de la plateforme visibles par le code de test : '.implode(', ', array_keys($report->failures)));
        $this->assertCount(\count(self::SECRETS) + 3, $report->cases, 'Le lanceur a bien tourné, avec le reste de l\'environnement (PATH).');
    }

    private function environment(string $testModule): Environment
    {
        $profile = new FrameworkProfile(
            id: 'maison', label: 'Maison', order: 99, console: '', consoleExample: '',
            bootNote: '', unpackLabel: '', testRunner: FrameworkProfile::VITEST, versionPackage: null,
            projectDirs: [], codeDirs: [], cacheDirs: [], testCaches: [], hidden: [],
            namespaceRoots: [], lessonLanguages: '', drafting: null, testModule: $testModule,
        );

        return new Environment('maison', 'Maison', '', [$this->dir], $profile, []);
    }
}
