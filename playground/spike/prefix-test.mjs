// Symfony génère-t-il des URLs correctes quand l'app est servie sous /preview/ ?
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP, PHPRequestHandler } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';

const ENV_DIR = fileURLToPath(new URL('../../environments/symfony-8', import.meta.url));
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
const php = new PHP(await loadNodeRuntime('8.4', { emscriptenOptions: { processId: 1 } }));
php.mkdir('/app');
copyInto(php, ENV_DIR, '/app');
php.writeFile('/app/src/Controller/DebugController.php', `<?php
namespace App\\Controller;
use Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController;
use Symfony\\Component\\HttpFoundation\\Request;
use Symfony\\Component\\Routing\\Attribute\\Route;
class DebugController extends AbstractController {
    #[Route('/debug/{nom}', name: 'debug')]
    public function debug(Request $r, string $nom) {
        return $this->json(['nom' => $nom, 'url' => $this->generateUrl('debug', ['nom' => 'x']), 'base' => $r->getBaseUrl(),
            'path' => $r->getPathInfo(), 'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'], 'REQUEST_URI' => $_SERVER['REQUEST_URI'], 'method' => $r->getMethod(), 'post' => $r->request->all()]);
    }
}`);
async function request(path, init = {}) {
	return php.run({
		scriptPath: '/app/public/index.php',
		relativeUri: path,
		method: init.method ?? 'GET',
		headers: { host: 'localhost:5173', ...(init.headers ?? {}) },
		body: init.body,
		$_SERVER: {
			SCRIPT_NAME: '/preview/index.php',
			PHP_SELF: '/preview/index.php',
			SCRIPT_FILENAME: '/app/public/index.php',
			DOCUMENT_ROOT: '/app/public',
		},
	});
}
for (const url of ['/preview/debug/taverne?x=1']) {
	const res = await request(url);
	console.log(url, '→', res.httpStatusCode, res.text.slice(0, 400));
}
const res = await request('/preview/debug/post', {
	method: 'POST',
	headers: { 'content-type': 'application/x-www-form-urlencoded' },
	body: new TextEncoder().encode('plat=tourte&prix=5'),
});
console.log('POST →', res.httpStatusCode, res.text.slice(0, 400));
