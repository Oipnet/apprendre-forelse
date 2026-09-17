// Spike chapitre 2 : Doctrine ORM + SQLite dans php-wasm (console, requêtes HTTP, PHPUnit).
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';

const ENV_DIR = fileURLToPath(new URL('../../environments/symfony-8-doctrine', import.meta.url));
const t0 = performance.now();
const lap = (label) => console.log(`[${(performance.now() - t0).toFixed(0).padStart(6)} ms] ${label}`);

function copyInto(php, dir, target) {
	for (const name of readdirSync(dir)) {
		if (dir === ENV_DIR && name === 'var') continue;
		const src = join(dir, name);
		const dst = join(target, name);
		if (statSync(src).isDirectory()) {
			php.mkdir(dst);
			copyInto(php, src, dst);
		} else php.writeFile(dst, readFileSync(src));
	}
}

let pid = 1;
async function newPhp() {
	const php = new PHP(await loadNodeRuntime('8.4', { emscriptenOptions: { processId: pid++ } }));
	php.mkdir('/app');
	copyInto(php, ENV_DIR, '/app');
	php.writeFile('/app/src/Entity/Plat.php', `<?php
namespace App\\Entity;
use Doctrine\\ORM\\Mapping as ORM;
#[ORM\\Entity]
class Plat {
    #[ORM\\Id, ORM\\GeneratedValue, ORM\\Column] public ?int $id = null;
    #[ORM\\Column(length: 100)] public string $nom = '';
    #[ORM\\Column] public float $prix = 0;
}`);
	php.writeFile('/app/src/Controller/CarteController.php', `<?php
namespace App\\Controller;
use App\\Entity\\Plat;
use Doctrine\\ORM\\EntityManagerInterface;
use Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController;
use Symfony\\Component\\Routing\\Attribute\\Route;
class CarteController extends AbstractController {
    #[Route('/carte')]
    public function carte(EntityManagerInterface $em) {
        return $this->json(array_map(fn (Plat $p) => [$p->nom, $p->prix], $em->getRepository(Plat::class)->findAll()));
    }
}`);
	php.mkdir('/runner');
	php.writeFile('/runner/console.php', `<?php
chdir('/app');
require '/app/vendor/autoload.php';
(new Symfony\\Component\\Dotenv\\Dotenv())->bootEnv('/app/.env');
$args = json_decode($_SERVER['CONSOLE_ARGS'], true);
$kernel = new App\\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$application = new Symfony\\Bundle\\FrameworkBundle\\Console\\Application($kernel);
$application->setAutoExit(false);
$input = new Symfony\\Component\\Console\\Input\\ArgvInput(['bin/console', ...$args]);
$input->setInteractive(false);
$code = $application->run($input, new Symfony\\Component\\Console\\Output\\StreamOutput(fopen('php://stdout', 'w'), decorated: false));
echo "\\n@@EXIT=$code";
`);
	return php;
}

async function consoleRun(php, ...args) {
	const start = performance.now();
	const res = await php.run({ scriptPath: '/runner/console.php', $_SERVER: { CONSOLE_ARGS: JSON.stringify(args) } });
	const out = res.text.trim();
	lap(`console ${args.join(' ')} (${(performance.now() - start).toFixed(0)} ms)\n    ${out.split('\n').filter(Boolean).slice(-4).join('\n    ')}`);
	if (res.errors) console.log('    stderr:', res.errors.slice(0, 400));
}

const php = await newPhp();
lap('instance prête');
await consoleRun(php, 'doctrine:schema:validate', '--skip-sync');
await consoleRun(php, 'doctrine:schema:update', '--force');
await consoleRun(php, 'dbal:run-sql', "INSERT INTO plat (nom, prix) VALUES ('Ragoût du nain', 12.5), ('Tourte elfique', 9)");
await consoleRun(php, 'dbal:run-sql', 'SELECT * FROM plat');

const res = await php.run({ scriptPath: '/app/public/index.php', relativeUri: '/carte', method: 'GET', $_SERVER: { SCRIPT_NAME: '/index.php', SCRIPT_FILENAME: '/app/public/index.php' } });
lap(`GET /carte → ${res.httpStatusCode} ${res.text.slice(0, 120)}`);

await consoleRun(php, 'make:migration', '--no-interaction');
console.log('    migrations :', php.listFiles('/app/migrations'));
await consoleRun(php, 'doctrine:migrations:status');

// PHPUnit sur une instance dédiée, schéma créé par le test lui-même.
const tests = await newPhp();
tests.writeFile('/app/tests/PlatTest.php', `<?php
namespace App\\Tests;
use App\\Entity\\Plat;
use Doctrine\\ORM\\Tools\\SchemaTool;
use Symfony\\Bundle\\FrameworkBundle\\Test\\KernelTestCase;
class PlatTest extends KernelTestCase {
    public function testPersistance(): void {
        $em = static::getContainer()->get('doctrine')->getManager();
        $tool = new SchemaTool($em);
        $tool->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $p = new Plat(); $p->nom = 'Hydromel'; $p->prix = 4.75;
        $em->persist($p); $em->flush(); $em->clear();
        $this->assertSame('Hydromel', $em->getRepository(Plat::class)->findOneBy(['nom' => 'Hydromel'])->nom);
    }
}`);
tests.mkdir('/runner');
tests.writeFile('/runner/run-tests.php', readFileSync(fileURLToPath(new URL('../src/runtime/run-tests.php', import.meta.url))));
const start = performance.now();
const report = (await tests.run({ scriptPath: '/runner/run-tests.php' })).text;
lap(`PHPUnit (${(performance.now() - start).toFixed(0)} ms) : ${report.slice(report.lastIndexOf('@@RAPPORT@@')).slice(0, 300)}`);
