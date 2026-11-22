import { describe, expect, it } from 'vitest';
import { hasPhpFatalError } from '../src/app/mentor.ts';

const corps = (texte: string) => new TextEncoder().encode(texte);

describe('hasPhpFatalError', () => {
	it("repère l'erreur que PHP écrit dans une page servie en 200 (display_errors)", () => {
		const page = '<br />\n<b>Parse error</b>:  syntax error, unexpected identifier "slogan" in <b>/app/public/index.php</b> on line <b>14</b><br />';
		expect(hasPhpFatalError(corps(page), 'text/html; charset=UTF-8')).toBe(true);
		expect(hasPhpFatalError(corps('PHP Fatal error:  Uncaught Error: Undefined constant "Saint"'), 'text/plain')).toBe(true);
	});

	it("ne prend pas pour une erreur une page qui en parle, ni ce qui n'est pas du texte", () => {
		expect(hasPhpFatalError(corps('<p>Une Parse error arrive quand un guillemet manque.</p>'), 'text/html')).toBe(false);
		expect(hasPhpFatalError(corps('<b>Parse error</b>: x'), 'image/png')).toBe(false);
	});
});
