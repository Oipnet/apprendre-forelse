/**
 * Complétion « Symfony-aware » sans serveur de langage : un index généré au build
 * par Reflection (tools/build-completion.php) + une analyse légère du fichier courant.
 *
 * PHP : `$this->` (méthodes héritées, ex. AbstractController::render), `$var->`
 * (type déduit des paramètres, `new`, retours de méthodes), `Classe::`, `#[` (attributs),
 * `new`, `use`, noms de classes avec ajout automatique du `use`, snippets Symfony.
 * Paramètres de l'appel en cours (signature help) : constructeurs, attributs, méthodes.
 * Twig : snippets, noms de routes dans path(), variables passées par les contrôleurs.
 */
import { monaco, pathOf } from './monaco';
import { projectClasses, type ClassInfo, type MethodInfo } from './project-classes';

type Languages = typeof monaco.languages;
const { CompletionItemKind: Kind, CompletionItemInsertTextRule: Rule } = monaco.languages;

export interface CompletionIndex {
	classes: Record<string, ClassInfo>;
}

/** `?Symfony\Component\HttpFoundation\Response $r = null` → `?Response $r = null` */
const shorten = (s: string) => s.replace(/(?:\\?[A-Za-z_]\w*\\)+([A-Za-z_]\w*)/g, '$1');

interface FileContext {
	namespace: string;
	/** alias court → FQCN */
	uses: Map<string, string>;
	/** FQCN de la classe parente du fichier (extends), si connue. */
	parent?: string;
	lastUseLine: number;
	namespaceLine: number;
}

function analyse(text: string): FileContext {
	const uses = new Map<string, string>();
	let lastUseLine = 0;
	let namespaceLine = 0;
	let namespace = '';
	text.split('\n').forEach((line, i) => {
		const ns = line.match(/^\s*namespace\s+([\w\\]+)\s*;/);
		if (ns) {
			namespace = ns[1];
			namespaceLine = i + 1;
		}
		const use = line.match(/^\s*use\s+([\w\\]+)(?:\s+as\s+(\w+))?\s*;/);
		if (use) {
			uses.set(use[2] ?? use[1].split('\\').pop()!, use[1]);
			lastUseLine = i + 1;
		}
	});
	const ctx: FileContext = { namespace, uses, lastUseLine, namespaceLine };
	const ext = text.match(/class\s+\w+\s+extends\s+([\w\\]+)/);
	if (ext) ctx.parent = resolveName(ext[1], ctx);
	return ctx;
}

function resolveName(name: string, ctx: FileContext): string {
	name = name.replace(/^\?/, '');
	if (name.startsWith('\\')) return name.slice(1);
	const [head, ...rest] = name.split('\\');
	const imported = ctx.uses.get(head);
	if (imported) return [imported, ...rest].join('\\');
	return ctx.namespace ? `${ctx.namespace}\\${name}` : name;
}

/** L'appel ouvert au curseur : sa parenthèse, les virgules de premier niveau et le texte de l'argument en cours. */
function openCall(code: string): { start: number; commas: number; current: string } | undefined {
	const stack: { start: number; commas: number; argStart: number; paren: boolean }[] = [];
	let quote = '';
	for (let i = 0; i < code.length; i++) {
		const ch = code[i];
		if (quote) {
			if (ch === '\\') i++;
			else if (ch === quote) quote = '';
			continue;
		}
		if (ch === "'" || ch === '"') quote = ch;
		else if ((ch === '/' && code[i + 1] === '/') || (ch === '#' && code[i + 1] !== '[')) {
			const end = code.indexOf('\n', i);
			if (end < 0) return undefined;
			i = end;
		} else if (ch === '/' && code[i + 1] === '*') {
			const end = code.indexOf('*/', i + 2);
			if (end < 0) return undefined;
			i = end + 1;
		} else if (ch === '(' || ch === '[' || ch === '{') stack.push({ start: i, commas: 0, argStart: i + 1, paren: ch === '(' });
		else if (ch === ')' || ch === ']' || ch === '}') stack.pop();
		else if (ch === ',' && stack.length) {
			const top = stack[stack.length - 1];
			top.commas++;
			top.argStart = i + 1;
		}
	}
	const top = stack[stack.length - 1];
	return top?.paren ? { start: top.start, commas: top.commas, current: code.slice(top.argStart) } : undefined;
}

export function registerCompletion(
	languages: Languages,
	index: CompletionIndex,
	allModels: () => monaco.editor.ITextModel[],
	framework: 'symfony' | 'laravel' | 'docker' | 'nuxt' = 'symfony',
	/** Fichiers du projet, contenu à jour : leurs classes s'ajoutent à l'index (voir refreshProject). */
	projectFiles: () => Record<string, string> = () => ({}),
) {
	const classes: Record<string, ClassInfo> = { ...index.classes };
	let projectSnapshot = '';

	/**
	 * Les classes de l'apprenant (`App\Entity\Plat`, le DTO qu'il vient d'écrire) ne sont dans aucun index :
	 * elles n'existent que le temps de l'exercice. On les relit de ses fichiers et on les fond dans l'index, si
	 * bien que tout ce qui suit — `new`, types, `use`, `::`, `->`, aide à la signature — les traite comme les
	 * autres, `use` ajouté automatiquement compris. Relu à chaque demande, analysé seulement au changement.
	 */
	function refreshProject() {
		const php = Object.entries(projectFiles()).filter(([path]) => path.endsWith('.php'));
		const snapshot = php.map(([path, code]) => `${path}\u0000${code}`).join('\u0001');
		if (snapshot === projectSnapshot) return;
		projectSnapshot = snapshot;
		for (const fqcn of Object.keys(classes)) delete classes[fqcn];
		Object.assign(classes, index.classes, projectClasses(Object.fromEntries(php), index.classes));
	}

	/** Méthode par nom, en tenant compte de l'héritage déjà aplati par l'index. */
	const methodsOf = (fqcn: string | undefined) => (fqcn ? (classes[fqcn]?.methods ?? []) : []);

	/** Type de retour exploitable : premier type non-null d'une union, `static`/`self` → classe appelée. */
	function returnClass(method: MethodInfo | undefined, calledOn: string): string | undefined {
		if (!method?.returns) return undefined;
		const type = method.returns.replace(/^\?/, '').split('|').find((t) => t !== 'null') ?? '';
		if (type === 'static' || type === 'self' || type === '$this') return calledOn;
		return classes[type] ? type : undefined;
	}

	/** Déduit la classe d'une variable à partir du code qui précède le curseur. */
	function typeOfVariable(name: string, code: string, ctx: FileContext, depth = 0): string | undefined {
		if (depth > 4) return undefined;
		if (name === 'this') return ctx.parent;
		const v = `\\$${name}\\b`;
		// Assignations : la plus récente gagne.
		const assignments = [...code.matchAll(new RegExp(`${v}\\s*=\\s*([^;]+);`, 'g'))];
		for (const [, expr] of assignments.reverse()) {
			const created = expr.match(/^new\s+([\w\\]+)/);
			if (created) return resolveName(created[1], ctx);
			const call = expr.match(/^(?:(static|self|parent)::|\$(\w+)->)(\w+)\(/);
			if (call) {
				const owner = call[1] ? ctx.parent : typeOfVariable(call[2], code, ctx, depth + 1);
				if (!owner) continue;
				const method = methodsOf(owner).find((m) => m.name === call[3]);
				return returnClass(method, owner);
			}
		}
		// Paramètre ou propriété typés : `Request $request`, `private Foo $foo`.
		const typed = code.match(new RegExp(`([\\\\\\w?]+)\\s+&?${v}`));
		if (typed && /^[?\\]?[A-Z]/.test(typed[1])) return resolveName(typed[1], ctx);
		return undefined;
	}

	function useEdit(fqcn: string, model: monaco.editor.ITextModel, ctx: FileContext): monaco.languages.TextEdit[] {
		const short = fqcn.split('\\').pop()!;
		const namespaceOf = fqcn.slice(0, fqcn.lastIndexOf('\\'));
		if (ctx.uses.get(short) === fqcn || namespaceOf === ctx.namespace) return [];
		// Après le dernier `use`, sinon après le namespace (ligne vide), sinon après `<?php`.
		const line = ctx.lastUseLine || ctx.namespaceLine || 1;
		const column = model.getLineMaxColumn(line);
		const separator = ctx.lastUseLine ? '\n' : '\n\n';
		return [{ range: new monaco.Range(line, column, line, column), text: `${separator}use ${fqcn};` }];
	}

	function methodItem(m: MethodInfo, range: monaco.IRange, sortPrefix = '1'): monaco.languages.CompletionItem {
		const signature = `(${m.params.map(shorten).join(', ')})${m.returns ? `: ${shorten(m.returns)}` : ''}`;
		return {
			label: { label: m.name, detail: shorten(signature), description: m.declaringClass },
			kind: Kind.Method,
			insertText: m.params.length ? `${m.name}($0)` : `${m.name}()`,
			insertTextRules: Rule.InsertAsSnippet,
			documentation: { value: `\`${m.declaringClass}::${m.name}${shorten(signature)}\`\n\n${m.doc}` },
			sortText: sortPrefix + m.name,
			range,
			command: m.params.length ? { id: 'editor.action.triggerParameterHints', title: '' } : undefined,
		};
	}

	function classItems(
		range: monaco.IRange,
		model: monaco.editor.ITextModel,
		ctx: FileContext,
		filter: (c: ClassInfo) => boolean,
		insert: (c: ClassInfo, fqcn: string) => string = (c) => c.short,
	): monaco.languages.CompletionItem[] {
		return Object.entries(classes)
			.filter(([, c]) => filter(c))
			.map(([fqcn, c]) => ({
				label: { label: c.short, description: fqcn },
				kind: c.kind === 'interface' ? Kind.Interface : c.kind === 'enum' ? Kind.Enum : Kind.Class,
				insertText: insert(c, fqcn),
				insertTextRules: Rule.InsertAsSnippet,
				documentation: c.doc,
				filterText: c.short,
				range,
				additionalTextEdits: useEdit(fqcn, model, ctx),
			}));
	}

	languages.registerCompletionItemProvider('php', {
		triggerCharacters: ['>', ':', '[', ' ', '\\'],
		provideCompletionItems(model, position) {
			refreshProject();
			const line = model.getLineContent(position.lineNumber).slice(0, position.column - 1);
			const code = model.getValueInRange(new monaco.Range(1, 1, position.lineNumber, position.column));
			const ctx = analyse(model.getValue());
			const word = model.getWordUntilPosition(position);
			const range = new monaco.Range(position.lineNumber, word.startColumn, position.lineNumber, word.endColumn);

			// $var-> / $this->
			const arrow = line.match(/\$(\w+)\s*->\s*\w*$/);
			if (arrow) {
				const owner = typeOfVariable(arrow[1], code, ctx);
				const inside = arrow[1] === 'this';
				const suggestions = methodsOf(owner)
					.filter((m) => inside || !m.protected)
					.map((m) => methodItem(m, range, m.protected ? '0' : '1'));
				return { suggestions };
			}

			// static:: / self:: / Classe::
			const scope = line.match(/\b(static|self|parent|[A-Z]\w*)\s*::\s*\w*$/);
			if (scope) {
				const owner = ['static', 'self', 'parent'].includes(scope[1]) ? ctx.parent : resolveName(scope[1], ctx);
				const info = owner ? classes[owner] : undefined;
				const suggestions: monaco.languages.CompletionItem[] = methodsOf(owner)
					.filter((m) => m.static)
					.map((m) => methodItem(m, range));
				for (const constant of info?.constants ?? []) suggestions.push({ label: constant, kind: Kind.Constant, insertText: constant, range });
				suggestions.push({ label: 'class', kind: Kind.Keyword, insertText: 'class', range });
				return { suggestions };
			}

			// #[ORM\Column] : attributs d'un namespace importé sous un alias (use Doctrine\ORM\Mapping as ORM).
			const aliased = line.match(/#\[\s*(?:.*,\s*)?(\w+)\\\w*$/);
			if (aliased && ctx.uses.has(aliased[1])) {
				const namespace = ctx.uses.get(aliased[1])! + '\\';
				const suggestions = Object.entries(classes)
					.filter(([fqcn, c]) => c.kind === 'attribute' && fqcn.startsWith(namespace) && !fqcn.slice(namespace.length).includes('\\'))
					.map(([fqcn, c]) => ({
						label: { label: c.short, description: fqcn },
						kind: Kind.Class,
						insertText: c.constructor.length ? `${c.short}($0)` : c.short,
						insertTextRules: Rule.InsertAsSnippet,
						documentation: { value: `${c.doc}\n\n\`${c.short}(${c.constructor.map(shorten).join(', ')})\`` },
						range,
					}));
				return { suggestions };
			}

			// #[Attribut]
			if (/#\[\s*\w*$/.test(line)) {
				return { suggestions: classItems(range, model, ctx, (c) => c.kind === 'attribute', (c, fqcn) => (fqcn.endsWith('\\Route') ? "Route('/${1:chemin}', name: '${2:app_nom}')" : c.short)) };
			}

			// use Symfony\...
			const useMatch = line.match(/^\s*use\s+([\w\\]*)$/);
			if (useMatch) {
				const start = position.column - useMatch[1].length;
				const useRange = new monaco.Range(position.lineNumber, start, position.lineNumber, position.column);
				return {
					suggestions: Object.entries(classes).map(([fqcn, c]) => ({ label: fqcn, kind: Kind.Class, insertText: fqcn, documentation: c.doc, range: useRange })),
				};
			}

			// new Classe(...)
			if (/\bnew\s+\w*$/.test(line)) {
				return {
					suggestions: classItems(range, model, ctx, (c) => c.kind === 'class' && !c.abstract, (c) => (c.constructor.length ? `${c.short}($0)` : `${c.short}()`)),
				};
			}

			// Noms de classes (types, appels statiques) + snippets Symfony.
			const suggestions = /^[A-Z]/.test(word.word) ? classItems(range, model, ctx, () => true) : [];
			suggestions.push(...framework === 'laravel' ? laravelSnippets(range, model, ctx, useEdit) : phpSnippets(range, model, ctx, useEdit));
			return { suggestions };
		},
	});

	// Paramètres de l'appel en cours : `new Classe(`, `#[Attribut(`, `$var->methode(`, `Classe::methode(`.
	function calleeOf(before: string, code: string, ctx: FileContext): { label: string; params: string[]; doc: string } | undefined {
		const created = before.match(/(?:\bnew\s+|#\[\s*)([\\\w]+)\s*$/);
		if (created) {
			const info = classes[resolveName(created[1], ctx)];
			return info ? { label: info.short, params: info.constructor, doc: info.doc } : undefined;
		}
		const method = (owner: string | undefined, name: string) => {
			if (!owner) return undefined;
			if (name === '__construct') {
				const info = classes[owner];
				return info ? { label: `${info.short}::__construct`, params: info.constructor, doc: info.doc } : undefined;
			}
			const m = methodsOf(owner).find((candidate) => candidate.name === name);
			return m ? { label: m.name, params: m.params, doc: m.doc } : undefined;
		};
		const property = before.match(/\$this\s*->\s*(\w+)\s*->\s*(\w+)\s*$/);
		if (property) return method(typeOfVariable(property[1], code, ctx), property[2]);
		const arrow = before.match(/\$(\w+)\s*->\s*(\w+)\s*$/);
		if (arrow) return method(typeOfVariable(arrow[1], code, ctx), arrow[2]);
		const scope = before.match(/(?:^|[^\w\\$])(static|self|parent|[A-Z\\][\w\\]*)\s*::\s*(\w+)\s*$/);
		if (scope) return method(['static', 'self', 'parent'].includes(scope[1]) ? ctx.parent : resolveName(scope[1], ctx), scope[2]);
		return undefined;
	}

	languages.registerSignatureHelpProvider('php', {
		signatureHelpTriggerCharacters: ['(', ','],
		signatureHelpRetriggerCharacters: [',', ':'],
		provideSignatureHelp(model, position) {
			refreshProject();
			const code = model.getValueInRange(new monaco.Range(1, 1, position.lineNumber, position.column));
			const call = openCall(code);
			if (!call) return null;
			const ctx = analyse(model.getValue());
			const target = calleeOf(code.slice(0, call.start), code, ctx);
			if (!target || !target.params.length) return null;
			const shown = target.params.map(shorten);
			let offset = target.label.length + 1;
			const parameters = shown.map((text) => {
				const label: [number, number] = [offset, offset + text.length];
				offset += text.length + 2;
				return { label };
			});
			// Argument nommé (`name: …`) : le paramètre de ce nom ; sinon, sa position.
			const named = call.current.match(/^\s*(\w+)\s*:(?!:)/);
			const byName = named ? shown.findIndex((p) => new RegExp(`\\$${named[1]}\\b`).test(p)) : -1;
			const variadic = shown.findIndex((p) => p.includes('...$'));
			let active = byName >= 0 ? byName : call.commas;
			if (active >= shown.length && variadic >= 0) active = variadic;
			return {
				value: {
					signatures: [{ label: `${target.label}(${shown.join(', ')})`, documentation: target.doc ? { value: target.doc } : undefined, parameters }],
					activeSignature: 0,
					activeParameter: active,
				},
				dispose() {},
			};
		},
	});

	// Dockerfile et compose.yaml : quelques squelettes, pour ne pas partir d'une page blanche.
	if (framework === 'docker') {
		languages.registerCompletionItemProvider('dockerfile', {
			provideCompletionItems(model, position) {
				const word = model.getWordUntilPosition(position);
				const range = new monaco.Range(position.lineNumber, word.startColumn, position.lineNumber, word.endColumn);
				return { suggestions: dockerfileSnippets(range) };
			},
		});
		languages.registerCompletionItemProvider('yaml', {
			provideCompletionItems(model, position) {
				const word = model.getWordUntilPosition(position);
				const range = new monaco.Range(position.lineNumber, word.startColumn, position.lineNumber, word.endColumn);
				return { suggestions: composeSnippets(range) };
			},
		});
	}

	languages.registerCompletionItemProvider('twig', {
		triggerCharacters: ["'", '"', ' ', '{'],
		provideCompletionItems(model, position) {
			const line = model.getLineContent(position.lineNumber).slice(0, position.column - 1);
			const word = model.getWordUntilPosition(position);
			const range = new monaco.Range(position.lineNumber, word.startColumn, position.lineNumber, word.endColumn);

			// path('…') / url('…') : routes nommées déclarées dans les contrôleurs du projet.
			if (/\b(path|url)\(\s*['"][\w.-]*$/.test(line)) {
				return { suggestions: routeNames(allModels()).map((name) => ({ label: name, kind: Kind.Value, insertText: name, range })) };
			}

			const suggestions: monaco.languages.CompletionItem[] = templateVariables(pathOf(model.uri), allModels()).map((v) => ({
				label: { label: v.name, description: `passée par ${v.controller}` },
				kind: Kind.Variable,
				insertText: v.name,
				range,
				sortText: '0' + v.name,
			}));
			suggestions.push(...twigSnippets(range));
			return { suggestions };
		},
	});
}

function snippet(label: string, insertText: string, documentation: string, range: monaco.IRange, extra: Partial<monaco.languages.CompletionItem> = {}): monaco.languages.CompletionItem {
	return { label, kind: Kind.Snippet, insertText, insertTextRules: Rule.InsertAsSnippet, documentation, range, sortText: '2' + label, ...extra };
}

function phpSnippets(
	range: monaco.IRange,
	model: monaco.editor.ITextModel,
	ctx: FileContext,
	useEdit: (fqcn: string, model: monaco.editor.ITextModel, ctx: FileContext) => monaco.languages.TextEdit[],
): monaco.languages.CompletionItem[] {
	const route = 'Symfony\\Component\\Routing\\Attribute\\Route';
	const response = 'Symfony\\Component\\HttpFoundation\\Response';
	return [
		snippet('route', "#[Route('/${1:chemin}', name: '${2:app_nom}')]", 'Attribut de route Symfony', range, {
			additionalTextEdits: useEdit(route, model, ctx),
		}),
		snippet(
			'action',
			"#[Route('/${1:chemin}', name: '${2:app_nom}')]\npublic function ${3:index}(): Response\n{\n\treturn \\$this->render('${4:dossier/template}.html.twig', [\n\t\t$0\n\t]);\n}",
			'Action de contrôleur complète (route + rendu Twig)',
			range,
			{ additionalTextEdits: [...useEdit(route, model, ctx), ...useEdit(response, model, ctx)] },
		),
		snippet('render', "return \\$this->render('${1:dossier/template}.html.twig', [\n\t'${2:variable}' => $0,\n]);", 'Rendu d\'un template Twig', range),
		snippet('id', "#[ORM\\Id]\n#[ORM\\GeneratedValue]\n#[ORM\\Column]\nprivate ?int \\$id = null;", 'Identifiant auto-incrémenté d\'une entité Doctrine', range),
		snippet('column', "#[ORM\\Column(length: ${1:255})]\nprivate ${2:?string} \\$${3:nom} = ${4:null};", 'Propriété mappée sur une colonne', range),
	];
}

/** Laravel : routes, contrôleurs, vues Blade (fichiers .blade.php, édités en mode PHP) et migrations. */
function laravelSnippets(
	range: monaco.IRange,
	model: monaco.editor.ITextModel,
	ctx: FileContext,
	useEdit: (fqcn: string, model: monaco.editor.ITextModel, ctx: FileContext) => monaco.languages.TextEdit[],
): monaco.languages.CompletionItem[] {
	const view = 'Illuminate\\View\\View';
	return [
		snippet('route', "Route::get('/${1:chemin}', [${2:Controleur}::class, '${3:index}'])->name('${4:nom}');", 'Route vers une méthode de contrôleur', range),
		snippet('action', "public function ${1:index}(): View\n{\n\treturn view('${2:dossier.vue}', [\n\t\t$0\n\t]);\n}", 'Méthode de contrôleur complète (rendu d\'une vue Blade)', range, {
			additionalTextEdits: useEdit(view, model, ctx),
		}),
		snippet('view', "return view('${1:dossier.vue}', [\n\t'${2:variable}' => $0,\n]);", 'Rendu d\'une vue Blade', range),
		snippet('foreach', '@foreach (${1:\\$elements} as ${2:\\$element})\n\t$0\n@endforeach', 'Boucle Blade', range),
		snippet('if', '@if (${1:condition})\n\t$0\n@endif', 'Condition Blade', range),
		snippet('extends', "@extends('${1:layouts.app}')\n\n@section('${2:content}')\n\t$0\n@endsection", 'Vue Blade héritant d\'un layout', range),
		snippet('migration', "Schema::create('${1:table}', function (Blueprint \\$table) {\n\t\\$table->id();\n\t$0\n\t\\$table->timestamps();\n});", 'Création de table dans une migration', range),
	];
}

/** Dockerfile : les instructions les plus fréquentes d'un projet PHP. */
function dockerfileSnippets(range: monaco.IRange): monaco.languages.CompletionItem[] {
	return [
		snippet('from', 'FROM php:${1:8.3}-${2|apache,fpm,cli,fpm-alpine,cli-alpine|}', 'Image de base', range),
		snippet('workdir', 'WORKDIR ${1:/var/www/html}', 'Dossier de travail des instructions suivantes', range),
		snippet('copy', 'COPY ${1:.} ${2:./}', 'Copier depuis le contexte de build', range),
		snippet('copy-from', 'COPY --from=${1:composer:2} ${2:/usr/bin/composer} ${3:/usr/bin/composer}', 'Copier depuis une autre image ou une autre étape', range),
		snippet('run-apt', 'RUN apt-get update \\\\\n    && apt-get install -y --no-install-recommends ${1:libicu-dev} \\\\\n    && docker-php-ext-install ${2:intl} \\\\\n    && rm -rf /var/lib/apt/lists/*', 'Installer des paquets et une extension PHP, en une seule couche', range),
		snippet('run-apk', 'RUN apk add --no-cache --virtual .build-deps ${1:icu-dev} \\\\\n    && docker-php-ext-install ${2:intl} \\\\\n    && apk add --no-cache ${3:icu-libs} \\\\\n    && apk del .build-deps', 'Installer une extension sur Alpine, puis retirer les outils de compilation', range),
		snippet('composer', 'COPY composer.json composer.lock ./\nRUN composer install --no-dev --optimize-autoloader --no-scripts', 'Installer les dépendances avant de copier le code (cache des couches)', range),
		snippet('env', 'ENV ${1:APP_ENV}=${2:prod}', "Variable d'environnement de l'image", range),
		snippet('arg', 'ARG ${1:APP_ENV}=${2:prod}', 'Argument de construction', range),
		snippet('expose', 'EXPOSE ${1:80}', 'Documenter le port du service', range),
		snippet('user', 'USER ${1:www-data}', 'Utilisateur des instructions suivantes et du processus', range),
		snippet('healthcheck', 'HEALTHCHECK --interval=${1:10s} --timeout=${2:3s} --retries=${3:3} \\\\\n    CMD curl -f http://localhost${4:/sante} || exit 1', 'Contrôle de santé du conteneur', range),
		snippet('entrypoint', 'ENTRYPOINT ["${1:docker-entrypoint.sh}"]\nCMD ["${2:apache2-foreground}"]', "Script d'entrée et commande par défaut", range),
		snippet('cmd', 'CMD ["${1:apache2-foreground}"]', 'Commande par défaut, en forme exec', range),
		snippet('docroot', "ENV APACHE_DOCUMENT_ROOT=${1:/var/www/html/public}\nRUN sed -ri -e 's!/var/www/html!\\${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf", "Changer le DocumentRoot d'Apache", range),
	];
}

/** compose.yaml : un service, un volume, un contrôle de santé. */
function composeSnippets(range: monaco.IRange): monaco.languages.CompletionItem[] {
	return [
		snippet('services', 'services:\n  ${1:app}:\n    build: .\n    ports:\n      - "${2:8080}:${3:80}"\n    environment:\n      ${4:APP_ENV}: ${5:dev}', 'Squelette de compose.yaml', range),
		snippet('service', '${1:app}:\n  build: .\n  ports:\n    - "${2:8080}:${3:80}"', 'Service construit depuis le Dockerfile', range),
		snippet('service-image', '${1:web}:\n  image: ${2:nginx:1.29-alpine}\n  ports:\n    - "${3:8080}:80"', "Service à partir d'une image", range),
		snippet('db', 'db:\n  image: postgres:18-alpine\n  environment:\n    POSTGRES_USER: ${1:criee}\n    POSTGRES_PASSWORD: \\${DB_PASSWORD}\n  volumes:\n    - db-data:/var/lib/postgresql\n  healthcheck:\n    test: ["CMD-SHELL", "pg_isready -U ${1:criee}"]\n    interval: 5s', 'Service PostgreSQL avec contrôle de santé', range),
		snippet('volumes', 'volumes:\n  - ./${1:src}:/var/www/html/${1:src}', 'Montage du code dans le conteneur', range),
		snippet('depends', 'depends_on:\n  ${1:db}:\n    condition: service_healthy', 'Dépendance conditionnée au contrôle de santé', range),
		snippet('healthcheck', 'healthcheck:\n  test: ["CMD", "curl", "-f", "http://localhost${1:/sante}"]\n  interval: ${2:10s}\n  retries: ${3:3}', 'Contrôle de santé du service', range),
	];
}

function twigSnippets(range: monaco.IRange): monaco.languages.CompletionItem[] {
	return [
		snippet('for', '{% for ${1:element} in ${2:elements} %}\n\t$0\n{% endfor %}', 'Boucle Twig', range),
		snippet('if', '{% if ${1:condition} %}\n\t$0\n{% endif %}', 'Condition Twig', range),
		snippet('block', '{% block ${1:body} %}\n\t$0\n{% endblock %}', 'Bloc Twig', range),
		snippet('extends', "{% extends '${1:base.html.twig}' %}", 'Héritage de template', range),
		snippet('path', "{{ path('${1}') }}", 'URL d\'une route nommée', range, { command: { id: 'editor.action.triggerSuggest', title: '' } }),
		snippet('asset', "{{ asset('${1}') }}", 'URL d\'un fichier de public/', range),
	];
}

function routeNames(models: monaco.editor.ITextModel[]): string[] {
	const names = new Set<string>();
	for (const model of models) {
		if (model.getLanguageId() !== 'php') continue;
		for (const match of model.getValue().matchAll(/#\[Route\([^)]*name:\s*['"]([^'"]+)['"]/g)) names.add(match[1]);
	}
	return [...names];
}

/** Variables passées à ce template par un `render('chemin', [...])` d'un contrôleur. */
function templateVariables(templatePath: string, models: monaco.editor.ITextModel[]) {
	const template = templatePath.replace(/^templates\//, '');
	const variables: { name: string; controller: string }[] = [];
	for (const model of models) {
		if (model.getLanguageId() !== 'php') continue;
		const controller = pathOf(model.uri).split('/').pop()!.replace('.php', '');
		for (const call of model.getValue().matchAll(/render\(\s*['"]([^'"]+)['"]\s*,\s*\[([\s\S]*?)\]\s*\)/g)) {
			if (call[1] !== template) continue;
			for (const key of call[2].matchAll(/['"](\w+)['"]\s*=>/g)) variables.push({ name: key[1], controller });
		}
	}
	return variables;
}
