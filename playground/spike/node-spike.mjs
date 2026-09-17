// Spike go/no-go : Symfony démarre-t-il dans php-wasm ?
// Copie l'environnement dans le FS mémoire (comme le fera le navigateur),
// sert une requête HTTP puis lance PHPUnit.
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP, PHPRequestHandler } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';

const ENV_DIR = fileURLToPath(new URL('../../environments/symfony-8', import.meta.url));
const SKIP = new Set(['var', '.git', 'node_modules']);
const t0 = performance.now();
const lap = (label) => console.log(`[${(performance.now() - t0).toFixed(0).padStart(6)} ms] ${label}`);

function copyInto(php, dir, target) {
	let count = 0;
	for (const name of readdirSync(dir)) {
		if (dir === ENV_DIR && SKIP.has(name)) continue;
		const src = join(dir, name);
		const dst = join(target, name);
		if (statSync(src).isDirectory()) {
			php.mkdir(dst);
			count += copyInto(php, src, dst);
		} else {
			php.writeFile(dst, readFileSync(src));
			count++;
		}
	}
	return count;
}

const php = new PHP(await loadNodeRuntime('8.4', { emscriptenOptions: { processId: 1 } }));
lap('runtime PHP 8.4 chargé');
php.mkdir('/app');
const files = copyInto(php, ENV_DIR, '/app');
lap(`${files} fichiers copiés dans /app`);

const ext = await php.run({ code: '<?php echo implode(" ", get_loaded_extensions());' });
console.log('Extensions :', ext.text);

const handler = new PHPRequestHandler({
	php,
	documentRoot: '/app/public',
	absoluteUrl: 'http://localhost/',
	getFileNotFoundAction: () => ({ type: 'internal-redirect', uri: '/index.php' }),
});

for (const url of ['/', '/', '/page-inconnue']) {
	const start = performance.now();
	const res = await handler.request({ url });
	const title = res.text.match(/<title>([^<]*)<\/title>/)?.[1] ?? res.text.slice(0, 300);
	lap(`GET ${url} → ${res.httpStatusCode} en ${(performance.now() - start).toFixed(0)} ms — ${title.trim()}`);
	if (res.errors) console.log('stderr:', res.errors);
}

// Ajout puis modification d'un contrôleur : Symfony voit-il les changements ?
const controller = (plat) => `<?php
namespace App\\Controller;
use Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController;
use Symfony\\Component\\HttpFoundation\\Response;
use Symfony\\Component\\Routing\\Attribute\\Route;
class MenuController extends AbstractController {
    #[Route('/menu')]
    public function menu(): Response { return new Response('<h1>${plat}</h1>'); }
}`;
php.writeFile('/app/src/Controller/MenuController.php', controller('Ragoût du nain'));
for (const plat of [null, 'Tourte elfique']) {
	if (plat) php.writeFile('/app/src/Controller/MenuController.php', controller(plat));
	const start = performance.now();
	const res = await handler.request({ url: '/menu' });
	lap(`GET /menu → ${res.httpStatusCode} en ${(performance.now() - start).toFixed(0)} ms — ${res.text.slice(0, 60)}`);
}

// PHPUnit via run() + script runner (cli() est incompatible avec run() sur une même instance).
php.writeFile(
	'/app/tests/SmokeTest.php',
	`<?php
namespace App\\Tests;
use Symfony\\Bundle\\FrameworkBundle\\Test\\WebTestCase;
class SmokeTest extends WebTestCase {
    public function testMenu(): void {
        $client = static::createClient();
        $client->request('GET', '/menu');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Tourte');
    }
    public function testQuiEchoue(): void {
        $this->assertSame('taverne', 'auberge');
    }
}`,
);
php.mkdir('/runner');
php.writeFile(
	'/runner/run-tests.php',
	`<?php
chdir('/app');
$_SERVER['argv'] = $argv = ['phpunit', '--log-junit', '/tmp/junit.xml', '--colors=never'];
$_SERVER['argc'] = count($argv);
require '/app/vendor/autoload.php';
echo "\\nEXIT=" . (new PHPUnit\\TextUI\\Application())->run($argv);
`,
);
for (let i = 1; i <= 2; i++) {
	const start = performance.now();
	const res = await php.run({ scriptPath: '/runner/run-tests.php' });
	lap(`PHPUnit #${i} en ${(performance.now() - start).toFixed(0)} ms`);
	if (i === 1) {
		console.log(res.text);
		if (res.errors) console.log('stderr:', res.errors);
		console.log(php.fileExists('/tmp/junit.xml') ? php.readFileAsText('/tmp/junit.xml') : 'pas de junit.xml');
	}
}
