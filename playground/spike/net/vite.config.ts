import { fileURLToPath } from 'node:url';
import { defineConfig, type Plugin } from 'vite';
function phpWasmAssets(): Plugin {
	return { name: 'php-wasm-assets', enforce: 'pre', async resolveId(source, importer) {
		if (!/\.(wasm|so|dat)$/.test(source) || !importer?.includes('@php-wasm')) return null;
		const r = await this.resolve(`${source}?url`, importer, { skipSelf: true }); return r?.id ?? null; } };
}
const stub = fileURLToPath(new URL('../../stubs/php-wasm-unused/index.js', import.meta.url));
export default defineConfig({
	root: fileURLToPath(new URL('.', import.meta.url)),
	plugins: [phpWasmAssets()],
	resolve: { alias: [{ find: /^@php-wasm\/(web|node)-(5-2|7-4|8-0|8-1|8-2|8-3|8-5)$/, replacement: stub }, { find: /^(node:)?(fs|net|http|https|zlib|child_process|dns|tls|os|path|stream|util|crypto|events|worker_threads|ws)$/, replacement: fileURLToPath(new URL('../../stubs/node/empty.js', import.meta.url)) }] },
	server: { port: 5199, fs: { allow: ['../..', '../../..'] } },
	optimizeDeps: { exclude: ['@php-wasm/web', '@php-wasm/universal', '@php-wasm/web-8-4'] },
});
