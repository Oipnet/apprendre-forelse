// L'AsciiSlugger fonctionne-t-il sans l'extension intl (absente de php-wasm) ?
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
const ENV_DIR = fileURLToPath(new URL('../../environments/symfony-8-app', import.meta.url));
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
use Symfony\\Component\\Form\\Forms; use Symfony\\Component\\Form\\Extension\\Core\\Type\\NumberType;
echo 'locale : ', \\Locale::getDefault(), "\\n";
foreach ([false, true] as $html5) {
  try {
    $f = Forms::createFormFactory()->createBuilder()->add('prix', NumberType::class, ['html5' => $html5])->getForm();
    $f->setData(['prix' => 12.5]);
    echo 'html5=', var_export($html5, true), ' affiché : ', $f->createView()['prix']->vars['value'];
    $f->submit(['prix' => '6.5']); echo ' · soumis 6.5 → ', var_export($f->get('prix')->getData(), true), "\\n";
  } catch (Throwable $e) { echo 'html5=', var_export($html5, true), ' ERREUR ', get_class($e), ' : ', substr($e->getMessage(), 0, 120), "\\n"; }
}` });
console.log(r.text, r.errors);
