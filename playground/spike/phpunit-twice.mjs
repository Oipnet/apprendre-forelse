// Diagnostic : pourquoi un 2e run PHPUnit sur la même instance bloque-t-il ?
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';

const ENV_DIR = fileURLToPath(new URL('../../environments/symfony-8', import.meta.url));
const variant = process.argv[2] ?? 'plain';

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
php.writeFile('/app/tests/UnitTest.php', `<?php
namespace App\\Tests;
use PHPUnit\\Framework\\TestCase;
class UnitTest extends TestCase { public function testOk(): void { $this->assertTrue(true); } }`);
if (variant === 'web' || variant === 'kernel') php.writeFile('/app/tests/WebTest.php', variant === 'web' ? `<?php
namespace App\\Tests;
use Symfony\\Bundle\\FrameworkBundle\\Test\\WebTestCase;
class WebTest extends WebTestCase { public function testHome(): void { $c = static::createClient(); $c->request('GET', '/'); $this->assertResponseStatusCodeSame(404); } }` : `<?php
namespace App\\Tests;
use Symfony\\Bundle\\FrameworkBundle\\Test\\KernelTestCase;
class WebTest extends KernelTestCase { public function testBoot(): void { self::bootKernel(); $this->assertTrue(true); } }`);
if (variant === 'fail') php.writeFile('/app/tests/FailTest.php', `<?php
namespace App\\Tests;
use PHPUnit\\Framework\\TestCase;
class FailTest extends TestCase { public function testKo(): void { $this->assertSame(1, 2); } }`);
php.mkdir('/runner');
const args = variant === 'no-cache' ? "'--do-not-cache-result'" : variant === 'no-junit' ? '' : "'--log-junit', '/tmp/junit.xml'";
php.writeFile('/runner/run-tests.php', `<?php
chdir('/app');
$argv = ['phpunit', '--colors=never', ${args}];
$_SERVER['argv'] = $argv;
require '/app/vendor/autoload.php';
echo "EXIT=" . (new PHPUnit\\TextUI\\Application())->run(array_values(array_filter($argv)));
`);
for (let i = 1; i <= 3; i++) {
	const start = performance.now();
	const res = await php.run({ scriptPath: '/runner/run-tests.php' });
	console.log(`[${variant}] run #${i} ${(performance.now() - start).toFixed(0)} ms → ${res.text.trim().split('\n').pop()}`);
}
