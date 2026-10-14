/**
 * Précompresse les fichiers du build (brotli, zstd, gzip) : Caddy sert ensuite le .br, le .zst ou le .gz posé à
 * côté de l'original (`file_server { precompressed }`, docker/Caddyfile), au lieu de recompresser à chaque
 * téléchargement. Le binaire PHP (≈ 20 Mo) et les données ICU (≈ 30 Mo) coûtaient environ une seconde de
 * processeur par nouveau visiteur ; un pic de trafic sur les exercices les faisait recompresser des centaines de fois.
 *
 * Lancé par le Dockerfile après `npm run build`. Usage : node precompresser.ts <dossier>
 * Une variante qui ne gagne pas au moins 10 % n'est pas écrite : Caddy sert alors l'original.
 * Brotli au niveau maximal : c'est la variante que les navigateurs choisissent, et elle allège le binaire PHP de
 * 0,8 Mo par rapport au niveau 9, au prix d'une minute de calcul par gros fichier. Les compressions tournent en
 * parallèle (pool de threads de libuv) : environ deux minutes et demie sur 4 cœurs, le temps des données ICU.
 */
import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { promisify } from 'node:util';
import { brotliCompress, constants, gzip, zstdCompress } from 'node:zlib';

const dossier = process.argv[2];
if (!dossier) {
	console.error('Usage : node precompresser.ts <dossier>');
	process.exit(1);
}

// Les mêmes types que la directive encode du Caddyfile ; les polices woff2 et les images sont déjà compressées.
const compressibles = /\.(js|mjs|css|json|wasm|dat|so|svg|html|txt|ttf)$/;
// En dessous, l'en-tête de la réponse pèse plus que le gain.
const tailleMinimale = 1024;

const variantes: Array<[string, (contenu: Buffer) => Promise<Buffer>]> = [
	[
		'.br',
		(contenu) =>
			promisify(brotliCompress)(contenu, {
				params: {
					[constants.BROTLI_PARAM_QUALITY]: constants.BROTLI_MAX_QUALITY,
					[constants.BROTLI_PARAM_SIZE_HINT]: contenu.length,
				},
			}),
	],
	['.zst', (contenu) => promisify(zstdCompress)(contenu, { params: { [constants.ZSTD_c_compressionLevel]: 19 } })],
	['.gz', (contenu) => promisify(gzip)(contenu, { level: 9 })],
];

const fichiers = readdirSync(dossier, { recursive: true, encoding: 'utf8' })
	.map((nom) => join(dossier, nom))
	.filter((chemin) => compressibles.test(chemin) && statSync(chemin).isFile() && statSync(chemin).size >= tailleMinimale);

const tailles = await Promise.all(
	fichiers.map(async (chemin) => {
		const contenu = readFileSync(chemin);
		const compresses = await Promise.all(
			variantes.map(async ([extension, compresser]) => {
				const compresse = await compresser(contenu);
				if (compresse.length > contenu.length * 0.9) return contenu.length;
				writeFileSync(chemin + extension, compresse);
				return compresse.length;
			}),
		);
		return [contenu.length, Math.min(contenu.length, ...compresses)];
	}),
);
const avant = tailles.reduce((total, [taille]) => total + taille, 0);
const apres = tailles.reduce((total, [, taille]) => total + taille, 0);
const mo = (octets: number) => (octets / 1024 / 1024).toFixed(1);
console.log(`Précompression de ${fichiers.length} fichiers : ${mo(avant)} Mo → ${mo(apres)} Mo (meilleure variante de chacun).`);
