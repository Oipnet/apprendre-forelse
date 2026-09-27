import { describe, expect, it } from 'vitest';
import { escapeHtml } from '../src/app/html.ts';

describe('escapeHtml', () => {
	it('échappe les cinq caractères significatifs', () => {
		expect(escapeHtml(`<a href="x" title='y'>&</a>`)).toBe('&lt;a href=&quot;x&quot; title=&#39;y&#39;&gt;&amp;&lt;/a&gt;');
	});

	it('laisse le reste intact, sans double échappement surprise', () => {
		expect(escapeHtml('Élève — 3 > 2')).toBe('Élève — 3 &gt; 2');
		expect(escapeHtml('&amp;')).toBe('&amp;amp;');
	});
});
