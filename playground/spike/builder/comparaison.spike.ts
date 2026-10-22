/**
 * Essai php-wasm-builder : les mêmes scénarios (aperçu, PHPUnit, console) sur @php-wasm et sur un PHP
 * compilé par lyrixx/php-wasm-builder, à travers PhpWasmProject, comme le playground s'en sert.
 * Les temps vont dans $GITHUB_STEP_SUMMARY quand il existe.
 */
import { appendFileSync } from 'node:fs';
import { afterAll, describe, expect, it } from 'vitest';
import type { FrameworkProfile } from '@forelse/runtime-contract';
import { laravel, projet as nouveauProjet, type Runtime, spec, symfony } from './commun.ts';

const mesures: string[] = [];
afterAll(() => {
	const tableau = ['| Runtime | Environnement | Étape | Durée | Résultat |', '|---|---|---|---|---|', ...mesures].join('\n');
	console.log(tableau);
	if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, `\n### Scénarios\n\n${tableau}\n`);
});

async function mesurer<T>(runtime: Runtime, env: string, etape: string, action: () => Promise<T>, resume: (r: T) => string): Promise<T> {
	const debut = performance.now();
	try {
		const resultat = await action();
		mesures.push(`| ${runtime} | ${env} | ${etape} | ${Math.round(performance.now() - debut)} ms | ${resume(resultat).replace(/\|/g, '\\|').replace(/\s+/g, ' ').slice(0, 160)} |`);
		return resultat;
	} catch (erreur) {
		mesures.push(`| ${runtime} | ${env} | ${etape} | ${Math.round(performance.now() - debut)} ms | ❌ ${String(erreur).replace(/\s+/g, ' ').slice(0, 160)} |`);
		throw erreur;
	}
}

async function demarrer(runtime: Runtime, env: string, framework: FrameworkProfile) {
	const projet = nouveauProjet(runtime);
	await mesurer(runtime, env, 'démarrage', () => projet.boot(spec(env, framework)), () => 'ok');
	return projet;
}

const decoder = new TextDecoder();
const texte = (corps: Uint8Array) => decoder.decode(corps);
const titre = (html: string) => html.match(/<title>([^<]*)<\/title>/)?.[1]?.trim() ?? html.slice(0, 80);
const bilan = (r: { exitCode: number; cases: { status: string }[]; output: string }) =>
	`exit ${r.exitCode}, ${r.cases.filter((c) => c.status === 'passed').length}/${r.cases.length} passés${r.cases.length ? '' : ` — ${r.output.slice(0, 120)}`}`;

const CONTROLEUR = `<?php
namespace App\\Controller;
use Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController;
use Symfony\\Component\\HttpFoundation\\{JsonResponse, Request, Response};
use Symfony\\Component\\Routing\\Attribute\\Route;
class MenuController extends AbstractController {
    #[Route('/menu')]
    public function menu(Request $request): Response {
        $request->getSession()->set('vu', 1 + $request->getSession()->get('vu', 0));
        return new Response('<html><head><title>Menu</title></head><body><h1>Tourte elfique</h1><p>vu '.$request->getSession()->get('vu').'</p></body></html>');
    }
    #[Route('/commande', methods: ['POST'])]
    public function commande(Request $request): JsonResponse {
        return new JsonResponse(['formulaire' => $request->request->all(), 'corps' => $request->getContent(), 'translit' => iconv('UTF-8', 'ASCII//TRANSLIT', 'Pâté')]);
    }
}`;

const TEST_SYMFONY = `<?php
namespace App\\Tests;
use Symfony\\Bundle\\FrameworkBundle\\Test\\WebTestCase;
class MenuTest extends WebTestCase {
    public function testMenu(): void {
        $client = static::createClient();
        $client->request('GET', '/menu');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Tourte');
    }
    public function testCommande(): void {
        $client = static::createClient();
        $client->request('POST', '/commande', ['plat' => 'tourte']);
        $this->assertSame('tourte', json_decode($client->getResponse()->getContent(), true)['formulaire']['plat']);
    }
}`;

const ROUTES_LARAVEL = `<?php
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\{Crypt, Route};
Route::get('/', fn () => view('welcome'));
Route::get('/secret', fn () => Crypt::decryptString(Crypt::encryptString('chut')));
Route::post('/api/echo', fn (Request $request) => response()->json($request->json()->all()))->withoutMiddleware(\\Illuminate\\Foundation\\Http\\Middleware\\VerifyCsrfToken::class);
`;

const TEST_LARAVEL = `<?php
namespace Tests\\Feature;
use Tests\\TestCase;
class AccueilTest extends TestCase {
    public function testAccueil(): void { $this->get('/')->assertOk(); }
    public function testSecret(): void { $this->get('/secret')->assertSee('chut'); }
    public function testEcho(): void { $this->postJson('/api/echo', ['plat' => 'tourte'])->assertJson(['plat' => 'tourte']); }
}`;

describe.each(['php-wasm', 'builder'] as Runtime[])('%s', (runtime) => {
	it('Symfony : aperçu, formulaire, corps JSON, PHPUnit, console', async () => {
		const projet = await demarrer(runtime, 'symfony-8', symfony);
		await projet.writeFiles({ 'src/Controller/MenuController.php': CONTROLEUR, 'tests/MenuTest.php': TEST_SYMFONY });
		const premiere = await mesurer(runtime, 'symfony-8', 'GET /menu (cache froid)', () => projet.request({ method: 'GET', url: '/preview/menu', headers: {} }), (r) => `${r.status} ${titre(texte(r.body))}`);
		expect.soft(premiere.status).toBe(200);
		const seconde = await mesurer(runtime, 'symfony-8', 'GET /menu (cache chaud)', () => projet.request({ method: 'GET', url: '/preview/menu', headers: {} }), (r) => `${r.status} ${texte(r.body).match(/vu \d+/)?.[0]}`);
		expect.soft(texte(seconde.body)).toContain('vu 2');
		const formulaire = await mesurer(runtime, 'symfony-8', 'POST formulaire', () => projet.request({ method: 'POST', url: '/preview/commande', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: new TextEncoder().encode('plat=tourte') }), (r) => `${r.status} ${texte(r.body)}`);
		expect.soft(texte(formulaire.body)).toContain('"plat":"tourte"');
		const json = await mesurer(runtime, 'symfony-8', 'POST JSON', () => projet.request({ method: 'POST', url: '/preview/commande', headers: { 'content-type': 'application/json' }, body: new TextEncoder().encode('{"plat":"tourte"}') }), (r) => `${r.status} ${texte(r.body)}`);
		expect.soft(JSON.parse(texte(json.body)).corps).toBe('{"plat":"tourte"}');
		const tests = await mesurer(runtime, 'symfony-8', 'PHPUnit', () => projet.runTests(), bilan);
		expect.soft(tests.cases.filter((c) => c.status === 'passed')).toHaveLength(2);
		await mesurer(runtime, 'symfony-8', 'PHPUnit (2e run)', () => projet.runTests(), bilan);
		const console = await mesurer(runtime, 'symfony-8', 'bin/console debug:router', () => projet.runCommand(['debug:router']), (r) => `exit ${r.exitCode}`);
		expect.soft(console.output).toContain('/commande');
	});

	it('Symfony + Doctrine : SQLite', async () => {
		const projet = await demarrer(runtime, 'symfony-8-doctrine', symfony);
		const sql = await mesurer(runtime, 'symfony-8-doctrine', 'dbal:run-sql', () => projet.runCommand(['dbal:run-sql', 'SELECT sqlite_version() AS v']), (r) => `exit ${r.exitCode} ${r.output.slice(-60)}`);
		expect.soft(sql.exitCode).toBe(0);
	});

	it('Laravel : aperçu, session chiffrée (openssl), PHPUnit, artisan', async () => {
		const projet = await demarrer(runtime, 'laravel-13', laravel);
		await projet.writeFiles({ 'routes/web.php': ROUTES_LARAVEL, 'tests/Feature/AccueilTest.php': TEST_LARAVEL });
		const accueil = await mesurer(runtime, 'laravel-13', 'GET /', () => projet.request({ method: 'GET', url: '/preview/', headers: {} }), (r) => `${r.status} ${titre(texte(r.body))} cookies: ${(r.headers['set-cookie'] ?? []).map((c) => c.split('=')[0]).join(',')}`);
		expect.soft(accueil.status).toBe(200);
		await mesurer(runtime, 'laravel-13', 'GET / (2e)', () => projet.request({ method: 'GET', url: '/preview/', headers: {} }), (r) => `${r.status}`);
		const secret = await mesurer(runtime, 'laravel-13', 'GET /secret', () => projet.request({ method: 'GET', url: '/preview/secret', headers: {} }), (r) => `${r.status} ${texte(r.body).slice(0, 80)}`);
		expect.soft(texte(secret.body)).toBe('chut');
		const tests = await mesurer(runtime, 'laravel-13', 'PHPUnit', () => projet.runTests(), bilan);
		expect.soft(tests.cases.filter((c) => c.status === 'passed')).toHaveLength(3);
		const artisan = await mesurer(runtime, 'laravel-13', 'artisan route:list', () => projet.runCommand(['route:list']), (r) => `exit ${r.exitCode}`);
		expect.soft(artisan.output).toContain('secret');
	});
});
