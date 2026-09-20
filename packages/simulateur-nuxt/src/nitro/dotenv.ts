/**
 * Fichier `.env` du projet, lu par `nuxi dev` au démarrage (c12, avec le parseur de dotenv) : une
 * variable déjà présente dans l'environnement l'emporte. Portage du `parse` de dotenv 16.
 */
const LINE = /(?:^|^)\s*(?:export\s+)?([\w.-]+)(?:\s*=\s*?|:\s+?)(\s*'(?:\\'|[^'])*'|\s*"(?:\\"|[^"])*"|\s*`(?:\\`|[^`])*`|[^#\r\n]+)?\s*(?:#.*)?(?:$|$)/gm;

export function parseDotenv(source: string | undefined): Record<string, string> {
	const result: Record<string, string> = {};
	if (!source) return result;
	const lines = source.replace(/\r\n?/gm, '\n');
	LINE.lastIndex = 0;
	let match: RegExpExecArray | null;
	while ((match = LINE.exec(lines)) != null) {
		const key = match[1];
		let value = (match[2] || '').trim();
		const maybeQuote = value[0];
		value = value.replace(/^(['"`])([\s\S]*)\1$/gm, '$2');
		if (maybeQuote === '"') {
			value = value.replace(/\\n/g, '\n').replace(/\\r/g, '\r');
		}
		result[key] = value;
	}
	return result;
}
