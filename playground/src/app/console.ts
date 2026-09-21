import type { CommandResult, Runtime } from '@forelse/runtime-contract';

/**
 * Découpe une ligne de commande en arguments (guillemets simples ou doubles respectés).
 *
 * Les `aliases` tolèrent les habitudes du terminal — « php bin/console … », « docker-compose up » —
 * et viennent du profil du framework : un préfixe reconnu est retiré, ou remplacé quand la valeur
 * n'est pas vide (« docker-compose » devient « compose »). Deux tours au plus, parce que « php » et
 * « bin/console » s'enchaînent.
 */
export function parseCommandLine(line: string, aliases: Record<string, string> = {}): string[] {
	const args: string[] = [];
	const pattern = /"((?:[^"\\]|\\.)*)"|'([^']*)'|(\S+)/g;
	for (const match of line.matchAll(pattern)) args.push(match[1]?.replace(/\\(.)/g, '$1') ?? match[2] ?? match[3]);
	for (let tour = 0; tour < 2; tour++) {
		const premier = args[0];
		if (premier === undefined || !(premier in aliases)) break;
		args.shift();
		const remplacement = aliases[premier]!;
		if (remplacement) {
			args.unshift(remplacement);
			break;
		}
	}
	return args;
}

const escapeHtml = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);

/**
 * Ce qu'un vrai terminal afficherait à la fin : les séquences OSC (titre, progression dans la barre
 * des tâches) disparaissent, et une ligne réécrite (barre de progression : retour chariot ou
 * effacement de ligne) ne garde que son dernier état.
 */
export function finalScreen(text: string): string {
	return text
		.replace(/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\)/g, '')
		.split('\n')
		.map((line) => line.split(/\r(?!$)|\x1b\[2K/).pop()!)
		.join('\n');
}

/** Convertit les couleurs ANSI (SGR) de la console Symfony en HTML. */
export function ansiToHtml(text: string): string {
	text = finalScreen(text);
	let fg: number | null = null;
	let bg: number | null = null;
	let bold = false;
	let html = '';
	const write = (textPart: string) => {
		if (!textPart) return;
		const classes = [fg !== null ? `fg-${fg}` : '', bg !== null ? `bg-${bg}` : '', bold ? 'bold' : ''].filter(Boolean);
		html += classes.length ? `<span class="${classes.join(' ')}">${escapeHtml(textPart)}</span>` : escapeHtml(textPart);
	};
	for (const part of text.split(/(\x1b\[[\d;]*[A-Za-z])/)) {
		const csi = part.match(/^\x1b\[([\d;]*)([A-Za-z])$/);
		if (!csi) {
			write(part);
			continue;
		}
		// SymfonyStyle remplit le fond de ses blocs en déplaçant le curseur (« C ») : des espaces.
		if (csi[2] === 'C') write(' '.repeat(Math.min(Number(csi[1]) || 1, 200)));
		if (csi[2] !== 'm') continue; // autres séquences (effacement…) : sans objet ici
		const sgr = csi;
		for (const code of (sgr[1] || '0').split(';').map(Number)) {
			if (code === 0) [fg, bg, bold] = [null, null, false];
			else if (code === 1) bold = true;
			else if (code === 22) bold = false;
			else if (code >= 30 && code <= 37) fg = code - 30;
			else if (code >= 90 && code <= 97) fg = code - 90 + 8;
			else if (code === 39) fg = null;
			else if (code >= 40 && code <= 47) bg = code - 40;
			else if (code === 49) bg = null;
		}
	}
	return html;
}

/** Console du projet (bin/console, artisan) dans l'aperçu : saisie, historique, rendu des sorties. */
export class ConsolePanel {
	private readonly history: string[] = [];
	private historyIndex = 0;
	private busy = false;

	constructor(
		private readonly output: HTMLElement,
		private readonly input: HTMLInputElement,
		private readonly runtime: Runtime,
		private readonly onCommandRan: (result: CommandResult) => void,
		/** Nom de la console, tel qu'on la tape dans un terminal. */
		private readonly consoleName = 'bin/console',
		/** Préfixes tolérés au début d'une commande, déclarés par le profil du framework. */
		private readonly aliases: Record<string, string> = {},
	) {
		input.addEventListener('keydown', (event) => {
			if (event.key === 'Enter') {
				event.preventDefault();
				void this.run(input.value);
			} else if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
				event.preventDefault();
				this.historyIndex = Math.max(0, Math.min(this.history.length, this.historyIndex + (event.key === 'ArrowUp' ? -1 : 1)));
				input.value = this.history[this.historyIndex] ?? '';
			}
		});
	}

	/** Un message de la console de la page affichée dans l'aperçu (erreur d'hydratation, avertissement de Vue…). */
	logFromPreview(level: 'warn' | 'error', message: string): void {
		const entry = document.createElement('div');
		entry.className = `console-entry preview-${level}${level === 'error' ? ' failed' : ''}`;
		entry.innerHTML = `<div class="console-command"><span class="prompt">${level === 'error' ? '✖' : '⚠'} navigateur</span></div><pre class="console-result"></pre>`;
		entry.querySelector('pre')!.textContent = message;
		this.output.append(entry);
		this.output.scrollTop = this.output.scrollHeight;
	}

	async run(commandLine: string, { quiet = false } = {}): Promise<CommandResult | null> {
		const line = commandLine.trim();
		const args = parseCommandLine(line, this.aliases);
		if (!args.length || this.busy) return null;
		this.busy = true;
		this.input.disabled = true;
		if (!quiet) {
			this.history.push(line);
			this.historyIndex = this.history.length;
			this.input.value = '';
		}
		const entry = document.createElement('div');
		entry.className = 'console-entry';
		// « sh lancer.sh » n'est pas une sous-commande de la console : on l'affiche tel quel.
		const script = this.consoleName === 'docker' && (args[0] === 'sh' || args[0]?.endsWith('.sh'));
		entry.innerHTML = `<div class="console-command"><span class="prompt">$${script ? '' : ` ${escapeHtml(this.consoleName)}`}</span> ${escapeHtml(args.join(' '))}</div><pre class="console-result">…</pre>`;
		this.output.append(entry);
		this.output.scrollTop = this.output.scrollHeight;
		try {
			const result = await this.runtime.runCommand(args);
			entry.classList.toggle('failed', result.exitCode !== 0);
			entry.querySelector('pre')!.innerHTML = (ansiToHtml(result.output) || '<span class="muted">(aucune sortie)</span>')
				+ (result.exitCode !== 0 ? `\n<span class="exit-code">code de sortie : ${result.exitCode}</span>` : '');
			this.onCommandRan(result);
			return result;
		} catch (error) {
			entry.classList.add('failed');
			entry.querySelector('pre')!.textContent = String(error);
			return null;
		} finally {
			this.busy = false;
			this.input.disabled = false;
			this.output.scrollTop = this.output.scrollHeight;
			if (!quiet) this.input.focus();
		}
	}
}
