import { GoravelRuntime } from '../src/browser/GoravelRuntime.ts';

const journal = document.getElementById('journal')!;
const log = (line: string) => {
	journal.textContent += `${line}\n`;
	console.log(`[demo] ${line}`);
};
const text = (body: Uint8Array) => new TextDecoder().decode(body);

const runtime = new GoravelRuntime();
const t0 = performance.now();
await runtime.boot(
	{
		id: 'goravel-1',
		framework: { id: 'goravel', runtime: 'goravel-wasm' } as never,
		archiveUrl: '/goravel-1.zip',
		previewBasePath: '/preview/demo',
		previewSecure: false,
	},
	(p) => log(`… ${p.label}`),
);
log(`démarré en ${Math.round(performance.now() - t0)} ms`);

for (const url of ['/preview/demo/', '/preview/demo/bonjour/Ada', '/preview/demo/rien']) {
	const r = await runtime.request({ method: 'GET', url, headers: {} });
	log(`${url} → ${r.status} (${r.durationMs.toFixed(1)} ms) ${text(r.body).replace(/\s+/g, ' ').slice(0, 80)}`);
	if (url.endsWith('/')) (document.getElementById('apercu') as HTMLIFrameElement).srcdoc = text(r.body);
}

let t = performance.now();
let tests = await runtime.runTests();
log(`tests en ${Math.round(performance.now() - t)} ms : ${tests.cases.map((c) => `${c.name} ${c.status}`).join(', ')}`);

const web = (await runtime.readFile('routes/web.go'))!;
await runtime.writeFile('routes/web.go', web.replace('"name": "Goravel"', '"name": "Bonjour depuis Go"'));
t = performance.now();
const r = await runtime.request({ method: 'GET', url: '/preview/demo/', headers: {} });
log(`après modification : ${r.status} en ${Math.round(performance.now() - t)} ms, ${text(r.body).includes('Bonjour depuis Go') ? 'modification visible' : 'modification ABSENTE'}`);
(document.getElementById('apercu') as HTMLIFrameElement).srcdoc = text(r.body);
tests = await runtime.runTests();
log(`tests après modification : ${tests.cases.map((c) => `${c.name} ${c.status}${c.summary ? ` (${c.summary.split('\n')[0].slice(0, 60)})` : ''}`).join(', ')}`);
log('FIN');
