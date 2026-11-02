import type { Runtime } from '@forelse/runtime-contract';
import { m } from '../i18n';
import { escapeHtml } from './html';

/**
 * La clé d'API de l'apprenant, pour les parcours IA (environnements qui déclarent `network:`).
 *
 * Elle reste dans l'onglet : sessionStorage (oubliée à sa fermeture, jamais localStorage), puis le worker qui a le réseau,
 * qui la donne à l'aperçu et à la console en variable d'environnement. Elle n'est écrite dans aucun fichier du projet :
 * ni sauvegarde du brouillon, ni lien partagé, ni vérificateur du serveur, ni mentor ne la voient, et les tests rejouent
 * des réponses enregistrées.
 */

/** Un fournisseur que l'environnement autorise, tel que le moteur l'envoie (EnvironmentRegistry::NETWORK_PROVIDERS). */
export interface NetworkProvider {
	provider: string;
	host: string;
}

/** La variable d'environnement que lit chaque bridge de Symfony AI (et le reste de l'écosystème). */
export const API_KEY_VARIABLES: Readonly<Record<string, string>> = {
	anthropic: 'ANTHROPIC_API_KEY',
	gemini: 'GEMINI_API_KEY',
	mistral: 'MISTRAL_API_KEY',
	openai: 'OPENAI_API_KEY',
};

const PROVIDER_LABELS: Readonly<Record<string, string>> = { anthropic: 'Anthropic', gemini: 'Gemini', mistral: 'Mistral', openai: 'OpenAI' };

const STORAGE_KEY = 'forelse.cle-ia';

interface StoredKey {
	provider: string;
	key: string;
}

/** Une clé, au plus, par onglet : celle du dernier fournisseur choisi. */
function load(storage: Storage | null, providers: NetworkProvider[]): StoredKey | null {
	try {
		const data = JSON.parse(storage?.getItem(STORAGE_KEY) ?? 'null') as Partial<StoredKey> | null;
		if (typeof data?.key !== 'string' || !data.key || !providers.some((p) => p.provider === data.provider)) return null;
		return { provider: data.provider!, key: data.key };
	} catch {
		return null;
	}
}

/** Les secrets à donner au runtime : une variable pour la clé, rien sans clé. */
export function secretsFor(stored: StoredKey | null): Record<string, string> {
	const variable = stored ? API_KEY_VARIABLES[stored.provider] : undefined;
	return stored && variable ? { [variable]: stored.key } : {};
}

/** Retire la clé d'un texte qui quitte l'onglet (l'erreur envoyée au mentor). */
export function redactSecrets(text: string, secrets: Record<string, string>): string {
	let redacted = text;
	for (const value of Object.values(secrets)) if (value.length >= 8) redacted = redacted.split(value).join('••••');
	return redacted;
}

export function apiKeyButtonHtml(): string {
	return `<button id="api-key" class="ghost" title="${m.apiKey.buttonTitle}" aria-haspopup="dialog"><span class="icon" aria-hidden="true">🔑</span><span class="label"> ${m.apiKey.button}</span></button>`;
}

/** Le bouton « Clé API » et sa boîte. `current()` : les secrets donnés au runtime, pour les retirer des textes envoyés. */
export class ApiKeyPanel {
	private stored: StoredKey | null;

	constructor(
		private readonly host: HTMLElement,
		private readonly button: HTMLButtonElement,
		private readonly providers: NetworkProvider[],
		private readonly runtime: Pick<Runtime, 'setSecrets'>,
		private readonly storage: Storage | null,
	) {
		this.stored = load(storage, providers);
		this.refresh();
		button.addEventListener('click', () => this.open());
		if (this.stored) void this.apply();
	}

	current(): Record<string, string> {
		return secretsFor(this.stored);
	}

	private refresh() {
		this.button.classList.toggle('has-key', this.stored !== null);
		this.button.title = this.stored ? m.apiKey.buttonTitleSet(PROVIDER_LABELS[this.stored.provider] ?? this.stored.provider) : m.apiKey.buttonTitle;
	}

	private async apply() {
		await this.runtime.setSecrets?.(this.current());
	}

	private save(stored: StoredKey | null) {
		this.stored = stored;
		try {
			if (stored) this.storage?.setItem(STORAGE_KEY, JSON.stringify(stored));
			else this.storage?.removeItem(STORAGE_KEY);
		} catch {
			// Stockage bloqué : la clé vaut pour cette page seulement.
		}
		this.refresh();
		return this.apply();
	}

	private open() {
		const dialog = document.createElement('dialog');
		dialog.className = 'feedback api-key-dialog';
		dialog.setAttribute('aria-labelledby', 'api-key-title');
		const chosen = this.stored?.provider ?? this.providers[0]?.provider;
		dialog.innerHTML = `<form method="dialog">
			<h2 id="api-key-title">${m.apiKey.title}</h2>
			<p>${m.apiKey.intro}</p>
			<label>${m.apiKey.provider}
				<select name="provider">${this.providers.map((p) => `<option value="${escapeHtml(p.provider)}"${p.provider === chosen ? ' selected' : ''}>${escapeHtml(PROVIDER_LABELS[p.provider] ?? p.provider)}</option>`).join('')}</select>
			</label>
			<label>${m.apiKey.key}
				<input name="key" type="password" autocomplete="off" spellcheck="false" placeholder="${this.stored ? m.apiKey.keySet : ''}">
			</label>
			<p class="hint">${m.apiKey.advice}</p>
			<p class="confirm-actions">
				${this.stored ? `<button type="button" class="ghost" data-forget>${m.apiKey.forget}</button>` : ''}
				<button type="button" class="ghost" data-close>${m.common.close}</button>
				<button type="submit" class="primary">${m.apiKey.use}</button>
			</p>
		</form>`;
		this.host.append(dialog);
		const form = dialog.querySelector('form')!;
		form.addEventListener('submit', (event) => {
			const data = new FormData(form);
			const key = String(data.get('key') ?? '').trim();
			const provider = String(data.get('provider') ?? '');
			if (key) void this.save({ provider, key });
			else if (this.stored && provider !== this.stored.provider) event.preventDefault();
		});
		dialog.querySelector('[data-forget]')?.addEventListener('click', () => {
			void this.save(null);
			dialog.close();
		});
		dialog.querySelector('[data-close]')!.addEventListener('click', () => dialog.close());
		dialog.addEventListener('close', () => dialog.remove());
		dialog.showModal();
	}
}
