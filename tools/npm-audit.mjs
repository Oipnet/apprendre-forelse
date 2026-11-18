#!/usr/bin/env node
/**
 * `npm audit --omit=dev --audit-level=high`, avec des avis ignorés nommément : pour un avis sans version corrigée,
 * le temps qu'elle sorte. Les autres avis high ou critical font toujours échouer la CI.
 *
 *     node tools/npm-audit.mjs <dossier> [GHSA-… à ignorer…]
 *
 * Chaque avis ignoré doit être noté dans la CI avec sa raison et retiré dès qu'un correctif est publié.
 */
import { execFileSync } from 'node:child_process';

const [prefix, ...ignored] = process.argv.slice(2);
if (!prefix) {
	console.error('usage : node tools/npm-audit.mjs <dossier> [GHSA-… à ignorer…]');
	process.exit(2);
}

let output;
try {
	output = execFileSync('npm', ['audit', '--omit=dev', '--json', '--prefix', prefix], { encoding: 'utf8' });
} catch (error) {
	output = error.stdout; // npm audit sort en erreur dès qu'il trouve un avis : le rapport JSON est là quand même
}
const report = JSON.parse(output);
if (report.error) {
	console.error(report.error.summary ?? report.error);
	process.exit(1);
}

const blocking = new Map();
const skipped = new Set();
for (const vulnerability of Object.values(report.vulnerabilities ?? {})) {
	for (const via of vulnerability.via) {
		if (typeof via !== 'object' || !['high', 'critical'].includes(via.severity)) continue;
		const id = via.url?.split('/').pop() ?? String(via.source);
		if (ignored.includes(id)) skipped.add(id);
		else blocking.set(id, `${via.severity} ${via.name} ${via.range} : ${via.title} (${via.url})`);
	}
}

for (const id of skipped) console.log(`${prefix} : ${id} ignoré (voir la CI)`);
for (const id of ignored.filter((id) => !skipped.has(id))) console.log(`${prefix} : ${id} n'apparaît plus, retirez-le de la CI`);
if (blocking.size) {
	console.error(`${prefix} : ${blocking.size} avis high ou critical`);
	for (const line of blocking.values()) console.error(`- ${line}`);
	process.exit(1);
}
console.log(`${prefix} : aucun avis high ou critical${skipped.size ? ' hors avis ignorés' : ''}`);
