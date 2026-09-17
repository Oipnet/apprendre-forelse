/**
 * Serveur de démonstration : sert un projet Nuxt par le simulateur, pour l'essayer dans un vrai navigateur
 * (hydratation, navigation) sans passer par la plateforme.
 *
 *     npm run demo                      # projet de référence de la conformité, port 3100
 *     npm run demo -- chemin/du/projet 3200
 *     npm run demo -- chemin/du/projet 3200 /preview/abc/   # servi sous un préfixe, comme l'aperçu de la plateforme
 */
import { createServer } from 'node:http';
import { join } from 'node:path';
import { NuxtSimulator } from '../src/index.ts';
import { buildClientBundle, loadProject } from '../src/node/index.ts';

const directory = process.argv[2] ?? join(import.meta.dirname, '../conformite/reference');
const port = Number(process.argv[3] ?? 3100);
const baseURL = process.argv[4] ?? '/';

const simulator = new NuxtSimulator(loadProject(directory), { clientBundle: await buildClientBundle(), baseURL });

createServer(async (req, res) => {
	const chunks: Buffer[] = [];
	for await (const chunk of req) chunks.push(chunk as Buffer);
	const headers = Object.fromEntries(Object.entries(req.headers).map(([name, value]) => [name, Array.isArray(value) ? value.join(', ') : value ?? '']));
	const response = await simulator.request({ method: req.method ?? 'GET', url: req.url ?? '/', headers, body: chunks.length ? Buffer.concat(chunks) : undefined });
	res.writeHead(response.status, response.statusText, response.headers);
	res.end(response.body);
}).listen(port, '127.0.0.1', () => console.log(`Simulateur Nuxt sur http://127.0.0.1:${port}${baseURL} (${directory})`));
