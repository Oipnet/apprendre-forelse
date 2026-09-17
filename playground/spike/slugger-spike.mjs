// L'AsciiSlugger fonctionne-t-il sans l'extension intl (absente de php-wasm) ?
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
const ENV_DIR = fileURLToPath(new URL('../../environments/symfony-8-doctrine', import.meta.url));
function copyInto(php, dir, target) {
	for (const name of readdirSync(dir)) {
		if (dir === ENV_DIR && name === 'var') continue;
		const src = join(dir, name), dst = join(target, name);
		if (statSync(src).isDirectory()) { php.mkdir(dst); copyInto(php, src, dst); } else php.writeFile(dst, readFileSync(src));
	}
}
const php = new PHP(await loadNodeRuntime('8.4', { emscriptenOptions: { processId: 1 } }));
php.mkdir('/app'); copyInto(php, ENV_DIR, '/app');
const r = await php.run({ code: `<?php require '/app/vendor/autoload.php';
echo 'intl : ', extension_loaded('intl') ? 'oui' : 'non', "\\n";
$s = new Symfony\\Component\\String\\Slugger\\AsciiSlugger();
foreach (["Soupe à l'oignon", 'Tourte elfique aux champignons', 'Crème brûlée du Dragon', 'Œufs brouillés & bière'] as $n) echo $n, ' → ', $s->slug($n)->lower(), "\\n";` });
console.log(r.text, r.errors);
