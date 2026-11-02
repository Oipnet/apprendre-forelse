// Spike (02/11/2026) : le PHP du navigateur joint-il les API d'IA ? npx vite build --config spike/net/vite.config.ts, puis
// servir le dossier produit et ouvrir ?php=<code PHP en base64>. Conclusions dans src/runtime/php-network.ts.
import { PHP, setPhpIniEntries } from '@php-wasm/universal';
import { loadWebRuntime, generateCertificate, certificateToPEM } from '@php-wasm/web';

const out = document.querySelector('#out')!;
const log = (s: string) => { out.textContent += '\n' + s; console.log(s); };

// Firefox et Safari refusent les corps de requête en flux (duplex: 'half'), Chrome les exige en HTTP/2 :
// on lit le corps en entier avant d'appeler fetch(). PHP envoie un Content-Length, le flux se ferme donc de lui-même.
const nativeFetch = globalThis.fetch.bind(globalThis);
globalThis.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
	if (input instanceof Request && input.body) {
		const body = await input.arrayBuffer();
		input = new Request(input, { body, duplex: undefined } as RequestInit);
	}
	return nativeFetch(input, init);
};

(async () => {
	const t0 = performance.now();
	const CAroot = await generateCertificate({
		subject: { commonName: 'Forelse CA', organizationName: 'Forelse', countryName: 'FR' },
		basicConstraints: { ca: true },
	});
	const php = new PHP(await loadWebRuntime('8.4', { tcpOverFetch: { CAroot } }));
	const pem = certificateToPEM(CAroot.certificate);
	php.writeFile('/internal/ca.pem', pem);
	await setPhpIniEntries(php, { 'openssl.cafile': '/internal/ca.pem', 'curl.cainfo': '/internal/ca.pem', allow_url_fopen: '1', disable_functions: '' });
	log('boot ' + Math.round(performance.now() - t0) + ' ms');
	if (new URLSearchParams(location.search).has('zip')) {
		const zip = new Uint8Array(await (await fetch('/ai.zip')).arrayBuffer());
		php.writeFile('/tmp/ai.zip', zip);
		const x = await php.run({ code: '<?php $z = new ZipArchive; var_dump($z->open("/tmp/ai.zip")); $z->extractTo("/ai"); echo "extrait";' });
		log(x.text + x.errors);
	}
	const code = new URLSearchParams(location.search).get('php') ?? '';
	const r = await php.run({ code: atob(code) });
	log(r.text);
	log('errors: ' + r.errors);
	(window as any).done = true;
})().catch((e) => { log('ERR ' + e?.stack); (window as any).done = true; });
