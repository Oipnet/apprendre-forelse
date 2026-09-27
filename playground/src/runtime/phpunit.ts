/** Ce que le runtime PHP sait lire des messages de PHPUnit. */

const clip = (line: string) => (line.length > 160 ? `${line.slice(0, 160)}…` : line);

/**
 * Le résumé d'un test manqué (TestCaseResult.summary) : le message pédagogique, sans l'en-tête
 * « Classe::méthode » ni la trace. Un message écrit par l'auteur de l'exercice suffit ; sinon on garde
 * le détail de PHPUnit.
 */
export function phpunitSummary(message: string): string {
	const lines = message.split('\n').filter((l) => l.trim() && !l.startsWith('/') && !/^App\\Tests\\\S+$/.test(l));
	const authored = lines.length > 1 && lines[1].startsWith('Failed asserting') && !lines[0].includes('\\');
	return lines.slice(0, authored ? 1 : 2).map(clip).join('\n');
}
