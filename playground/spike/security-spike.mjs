// Spike chapitre 4 : SecurityBundle dans php-wasm (bcrypt, connexion par formulaire, session, rôles).
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { HttpCookieStore, PHP } from '@php-wasm/universal';
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

// 1. Coût de bcrypt
const bench = await php.run({ code: `<?php foreach ([4, 10, 13] as $cost) { $t = microtime(true); password_hash('secret', PASSWORD_BCRYPT, ['cost' => $cost]); printf("bcrypt cost %d : %d ms\\n", $cost, (microtime(true) - $t) * 1000); }
echo 'argon2id disponible : ', defined('PASSWORD_ARGON2ID') ? 'oui' : 'non', ' · sodium : ', extension_loaded('sodium') ? 'oui' : 'non', "\\n";` });
console.log(bench.text);

// 2. Connexion par formulaire
const hash = (await php.run({ code: `<?php echo password_hash('dragon', PASSWORD_BCRYPT, ['cost' => 4]);` })).text;
php.writeFile('/app/config/packages/security.yaml', `security:
    password_hashers:
        Symfony\\Component\\Security\\Core\\User\\PasswordAuthenticatedUserInterface: 'auto'
    providers:
        employes:
            memory:
                users:
                    bruna: { password: '${hash}', roles: ['ROLE_CUISINIER'] }
    firewalls:
        main:
            lazy: true
            provider: employes
            form_login: { login_path: app_login, check_path: app_login, enable_csrf: true }
            logout: { path: app_logout }
    access_control:
        - { path: ^/cuisine, roles: ROLE_CUISINIER }
`);
php.writeFile('/app/src/Controller/SecuriteController.php', `<?php
namespace App\\Controller;
use Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController;
use Symfony\\Component\\HttpFoundation\\Response;
use Symfony\\Component\\Routing\\Attribute\\Route;
use Symfony\\Component\\Security\\Http\\Authentication\\AuthenticationUtils;
class SecuriteController extends AbstractController {
    #[Route('/connexion', name: 'app_login')]
    public function login(AuthenticationUtils $u): Response {
        $e = $u->getLastAuthenticationError();
        return new Response('<form method="post"><input name="_username"><input name="_password"><input type="hidden" name="_csrf_token" value="'.$this->container->get('security.csrf.token_manager')->getToken('authenticate').'"></form>'.($e ? 'ERREUR:'.$e->getMessageKey() : ''));
    }
    #[Route('/deconnexion', name: 'app_logout')] public function logout(): never { throw new \\LogicException(); }
    #[Route('/cuisine', name: 'app_cuisine')] public function cuisine(): Response { return new Response('Bonjour '.$this->getUser()->getUserIdentifier().' '.json_encode($this->getUser()->getRoles())); }
}`);
const cookies = new HttpCookieStore();
async function request(method, path, form) {
	const t = performance.now();
	const body = form ? new TextEncoder().encode(new URLSearchParams(form).toString()) : undefined;
	const cookie = cookies.getCookieRequestHeader();
	const r = await php.run({
		scriptPath: '/app/public/index.php', relativeUri: path, method,
		headers: { host: 'localhost', ...(form ? { 'content-type': 'application/x-www-form-urlencoded', origin: 'http://localhost', referer: 'http://localhost/connexion' } : {}), ...(cookie ? { cookie } : {}) },
		body, $_SERVER: { SCRIPT_NAME: '/index.php', SCRIPT_FILENAME: '/app/public/index.php' },
	}).catch((e) => e.response);
	cookies.rememberCookiesFromResponseHeaders(r.headers);
	console.log(`${method} ${path} → ${r.httpStatusCode} ${r.headers.location ?? ''} (${Math.round(performance.now() - t)} ms) ${r.text.replace(/<[^>]+>/g, ' ').trim().slice(0, 90)}`);
	return r;
}
await request('GET', '/cuisine');
const login = await request('GET', '/connexion');
const token = login.text.match(/name="_csrf_token" value="([^"]*)"/)?.[1];
await request('POST', '/connexion', { _username: 'bruna', _password: 'mauvais', _csrf_token: token });
await request('POST', '/connexion', { _username: 'bruna', _password: 'dragon', _csrf_token: token });
await request('GET', '/cuisine');
await request('GET', '/deconnexion');
await request('GET', '/cuisine');
