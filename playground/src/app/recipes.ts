/**
 * Les recettes Symfony Flex des paquets du catalogue (bac à sable Symfony) : lues à l'empaquetage, sur les dépôts de
 * Flex (environments/bin/recettes-flex.php), appliquées ici comme Flex le fait après un `composer require`, et défaites
 * comme après un `composer remove`.
 *
 * - le bundle est déclaré dans config/bundles.php ;
 * - les fichiers de la recette (config/packages/…, src/…) sont copiés, sauf s'ils existent déjà : on ne remplace pas
 *   le travail de l'apprenant ;
 * - le bloc `###> paquet ###` est ajouté au .env.
 *
 * Défaire retire le bundle et le bloc du .env, et supprime les fichiers de la recette restés tels quels : un fichier
 * modifié reste, et on le dit.
 */

export interface Recipe {
	paquet: string;
	origine: string;
	bundles: Record<string, string[]>;
	fichiers: Record<string, string>;
	env: string[];
	message: string;
	ignore: string[];
}

export interface ProjectFiles {
	readFile(path: string): Promise<string | null>;
	writeFile(path: string, content: string): Promise<void>;
	deleteFile(path: string): Promise<void>;
}

/** Ce qu'une recette a changé dans le projet, pour l'éditeur et l'explorateur. */
export interface RecipeChanges {
	fichiers: Record<string, string>;
	supprimes: string[];
	/** Les fichiers de la recette modifiés par l'apprenant, gardés au retrait. */
	gardes: string[];
}

const BUNDLES = 'config/bundles.php';
const ENV = '.env';

/** Une recette telle que le catalogue la donne ; une entrée incomplète ou un chemin douteux l'écartent. */
export function parseRecipe(data: unknown): Recipe | null {
	const r = data as Partial<Recipe> | null;
	if (!r || typeof r !== 'object' || typeof r.paquet !== 'string') return null;
	const fichiers: Record<string, string> = {};
	for (const [path, content] of Object.entries(r.fichiers ?? {})) {
		if (typeof content !== 'string' || path.startsWith('/') || path.split('/').some((part) => part === '..' || part === '' || part === '.')) continue;
		fichiers[path] = content;
	}
	const bundles: Record<string, string[]> = {};
	for (const [classe, envs] of Object.entries(r.bundles ?? {})) {
		if (/^[A-Za-z_\\][\w\\]*$/.test(classe) && Array.isArray(envs)) bundles[classe.replace(/^\\/, '')] = envs.filter((e) => typeof e === 'string' && /^\w+$/.test(e));
	}
	return {
		paquet: r.paquet,
		origine: typeof r.origine === 'string' ? r.origine : r.paquet,
		bundles,
		fichiers,
		env: Array.isArray(r.env) ? r.env.filter((line): line is string => typeof line === 'string' && !line.includes('\n')) : [],
		message: typeof r.message === 'string' ? r.message : '',
		ignore: Array.isArray(r.ignore) ? r.ignore.filter((i): i is string => typeof i === 'string') : [],
	};
}

/** La ligne de config/bundles.php d'un bundle, comme Flex l'écrit. */
function bundleLine(classe: string, envs: string[]): string {
	return `    ${classe}::class => [${envs.map((env) => `'${env}' => true`).join(', ')}],`;
}

const declares = (content: string, classe: string) => new RegExp(`^\\s*\\\\?${classe.replace(/\\/g, '\\\\')}::class\\s*=>`, 'm').test(content);

/** config/bundles.php avec ces bundles en plus (à la fin, comme Flex), sans doublon. */
export function addBundles(content: string, bundles: Record<string, string[]>): string {
	const lines = Object.entries(bundles)
		.filter(([classe]) => !declares(content, classe))
		.map(([classe, envs]) => bundleLine(classe, envs));
	if (!lines.length) return content;
	const end = content.lastIndexOf('];');
	if (end < 0) return content;
	const before = content.slice(0, end).replace(/\s*$/, '\n');
	return `${before}${lines.join('\n')}\n${content.slice(end)}`;
}

/** config/bundles.php sans ces bundles. */
export function removeBundles(content: string, classes: string[]): string {
	return content
		.split('\n')
		.filter((line) => !classes.some((classe) => declares(line, classe)))
		.join('\n');
}

const marks = (paquet: string) => [`###> ${paquet} ###`, `###< ${paquet} ###`] as const;

/** Le .env avec le bloc de ce paquet, s'il n'y est pas déjà. */
export function addEnvBlock(content: string, paquet: string, lines: string[]): string {
	const [open, close] = marks(paquet);
	if (!lines.length || content.includes(open)) return content;
	return `${content.replace(/\n*$/, '\n')}\n${open}\n${lines.join('\n')}\n${close}\n`;
}

/** Le .env sans le bloc de ce paquet (et la ligne vide qui le précédait). */
export function removeEnvBlock(content: string, paquet: string): string {
	const [open, close] = marks(paquet);
	const start = content.indexOf(open);
	const end = content.indexOf(close, start);
	if (start < 0 || end < 0) return content;
	const after = content.slice(end + close.length).replace(/^\n/, '');
	return `${content.slice(0, start).replace(/\n+$/, '\n')}${after}`;
}

/** Applique une recette au projet : ce qui existe déjà n'est pas touché. */
export async function applyRecipe(project: ProjectFiles, recipe: Recipe, changes: RecipeChanges): Promise<void> {
	if (Object.keys(recipe.bundles).length) {
		const avant = (await project.readFile(BUNDLES)) ?? '';
		const apres = addBundles(avant || '<?php\n\nreturn [\n];\n', recipe.bundles);
		if (apres !== avant) await write(project, BUNDLES, apres, changes);
	}
	for (const [path, content] of Object.entries(recipe.fichiers)) {
		if ((await project.readFile(path)) === null) await write(project, path, content, changes);
	}
	if (recipe.env.length) {
		const avant = (await project.readFile(ENV)) ?? '';
		const apres = addEnvBlock(avant, recipe.paquet, recipe.env);
		if (apres !== avant) await write(project, ENV, apres, changes);
	}
}

/** Défait une recette : bundle, bloc du .env, et fichiers restés tels que la recette les a posés. */
export async function unapplyRecipe(project: ProjectFiles, recipe: Recipe, changes: RecipeChanges): Promise<void> {
	const classes = Object.keys(recipe.bundles);
	if (classes.length) {
		const avant = await project.readFile(BUNDLES);
		if (avant !== null) {
			const apres = removeBundles(avant, classes);
			if (apres !== avant) await write(project, BUNDLES, apres, changes);
		}
	}
	for (const [path, content] of Object.entries(recipe.fichiers)) {
		const actuel = await project.readFile(path);
		if (actuel === null) continue;
		if (actuel === content) {
			await project.deleteFile(path);
			delete changes.fichiers[path];
			changes.supprimes.push(path);
		} else {
			changes.gardes.push(path);
		}
	}
	if (recipe.env.length) {
		const avant = await project.readFile(ENV);
		if (avant !== null) {
			const apres = removeEnvBlock(avant, recipe.paquet);
			if (apres !== avant) await write(project, ENV, apres, changes);
		}
	}
}

async function write(project: ProjectFiles, path: string, content: string, changes: RecipeChanges): Promise<void> {
	await project.writeFile(path, content);
	changes.fichiers[path] = content;
	changes.supprimes = changes.supprimes.filter((p) => p !== path);
}

/** Ce que Flex écrit dans le terminal après un require : les recettes appliquées, puis leurs messages. */
export function recipesReport(recipes: Recipe[], unconfigure = false): string {
	if (!recipes.length) return '';
	const lines = [`Symfony operations: ${recipes.length} recipe${recipes.length > 1 ? 's' : ''}`];
	for (const recipe of recipes) lines.push(`  - ${unconfigure ? 'Unconfiguring' : 'Configuring'} ${recipe.paquet}${unconfigure ? '' : ` (${recipe.origine})`}`);
	if (!unconfigure) {
		for (const recipe of recipes.filter((r) => r.message.trim())) lines.push('', ` ${recipe.paquet}  instructions:`, '', recipe.message);
	}
	return lines.join('\n');
}
