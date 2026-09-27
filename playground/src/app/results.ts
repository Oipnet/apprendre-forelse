import type { TestCaseResult } from '@forelse/runtime-contract';

const clip = (line: string) => (line.length > 160 ? `${line.slice(0, 160)}…` : line);

/**
 * Ce qu'on montre sous un objectif manqué : le résumé du runtime, qui sait lire les messages de son
 * lanceur de tests ; à défaut, les deux premières lignes du message, sans les chemins de la trace.
 */
export function summaryOf(test: TestCaseResult): string {
	if (test.summary !== undefined) return test.summary;
	const lines = (test.message ?? '').split('\n').filter((l) => l.trim() && !l.startsWith('/'));
	return lines.slice(0, 2).map(clip).join('\n');
}
